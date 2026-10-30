<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaRecurso;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use App\Support\HorarioBiblioteca;
use App\Support\Reportes;
use App\Support\Texto;
use Illuminate\Support\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function opciones()
    {
        return response()->json([
            'carreras' => Usuario::whereNotNull('carrera')->distinct()->orderBy('carrera')->pluck('carrera'),
            'aniosIngreso' => Usuario::whereNotNull('anio_ingreso')->distinct()->orderBy('anio_ingreso')->pluck('anio_ingreso'),
            'sexos' => Usuario::whereNotNull('sexo')->distinct()->orderBy('sexo')->pluck('sexo'),
            'tiposUsuario' => ['estudiante', 'docente', 'funcionario'],
            // Nombre de cada recurso que se presta, para el gráfico «Recurso»: los libros y
            // las categorías de recursos de apoyo (también las desactivadas, que tienen historia).
            'recursos' => ['libro' => 'Material de la colección', ...CategoriaRecurso::orderBy('nombre')->pluck('nombre_plural', 'clave')->all()],
            // Tipos de material del catálogo (libro, tesis, revista, juego…), para filtrar los préstamos.
            'tiposMaterial' => TipoMaterial::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Reporte de un período concreto (el día, semana, mes, semestre o año que contiene
     * `fecha`, hoy por defecto), con la comparación contra el período anterior.
     *
     * Todo se cuenta en la base con GROUP BY: un año de préstamos o de ingresos son
     * decenas de miles de filas, y traerlas a memoria una por una dejaba al servidor
     * sin memoria. Lo que llega a PHP son solo los totales por grupo.
     */
    public function resumen(Request $request)
    {
        $tab = $request->query('tab', 'prestamos');
        $periodo = in_array($request->query('periodo'), Reportes::PERIODOS, true) ? $request->query('periodo') : 'mes';
        try {
            $referencia = Carbon::parse($request->query('fecha', now()->toDateString()));
        } catch (\Throwable) {
            $referencia = now();
        }

        [$inicio, $fin] = Reportes::rango($referencia, $periodo);
        [$inicioAnterior, $finAnterior] = Reportes::rango(Reportes::anterior($referencia, $periodo), $periodo);

        // Momento de cada registro: la entrada, el inicio de la reserva o el préstamo.
        $momento = match ($tab) {
            'ingresos' => 'entradas.fecha_hora_entrada',
            'logias' => '(reservas.fecha + reservas.hora_inicio)',
            default => 'prestamos.fecha_prestamo',
        };

        $consulta = function (Carbon $desde, Carbon $hasta) use ($tab, $request): QueryBuilder {
            $tabla = match ($tab) {
                'ingresos' => 'entradas',
                'logias' => 'reservas',
                default => 'prestamos',
            };
            // leftJoin: un externo (sin usuario) cuenta igual, como «Sin dato».
            $query = DB::table($tabla);
            if ($tab === 'logias') {
                // Una fila por integrante, no solo por quien hizo la reserva: el que va de invitado
                // también cuenta en Quiénes, carrera, sexo y año de ingreso. Lo que cuenta reservas
                // (el total, las salas, las horas) las cuenta sin repetir: ver $porReserva abajo.
                $query->leftJoin('reserva_participantes as rp', 'rp.reserva_id', '=', 'reservas.id')
                    ->leftJoin('usuarios', 'usuarios.id', '=', DB::raw('coalesce(rp.usuario_id, reservas.usuario_id)'));
            } else {
                $query->leftJoin('usuarios', 'usuarios.id', '=', "{$tabla}.usuario_id");
            }

            // De qué tipo de material es lo prestado (libro, tesis, juego educativo…): todo lo del
            // catálogo se guarda como tipo_item «libro», así que el tipo sale del título de la copia.
            if (in_array($tab, ['prestamos', 'libros'], true)) {
                $query->leftJoin('ejemplares as ej', 'ej.id', '=', 'prestamos.ejemplar_id')
                    ->leftJoin('libros as lib', 'lib.id', '=', 'ej.libro_id')
                    ->leftJoin('tipos_material as tm', 'tm.id', '=', 'lib.tipo_material_id');

                if ($tipoMaterial = $request->query('tipo_material')) {
                    $query->where('prestamos.tipo_item', 'libro')->where('tm.id', (int) $tipoMaterial);
                }
            }

            match ($tab) {
                'ingresos' => $query->whereBetween('entradas.fecha_hora_entrada', [$desde, $hasta]),
                // Las reservas canceladas no cuentan como uso de la sala.
                'logias' => $query->leftJoin('salas', 'salas.id', '=', 'reservas.sala_id')
                    ->where('reservas.estado', '!=', 'cancelada')
                    ->whereBetween('reservas.fecha', [$desde->toDateString(), $hasta->toDateString()]),
                'libros' => $query->where('prestamos.tipo_item', 'libro')->whereBetween('prestamos.fecha_prestamo', [$desde, $hasta]),
                default => $query->whereBetween('prestamos.fecha_prestamo', [$desde, $hasta]),
            };

            // Préstamos de recurso bibliográfico (libros) o de recursos de apoyo (notebooks, bicicletas…).
            if ($tab === 'prestamos' && $request->query('tipo_prestamo') === 'bibliografico') {
                $query->where('prestamos.tipo_item', 'libro');
            } elseif ($tab === 'prestamos' && $request->query('tipo_prestamo') === 'en_sala') {
                $query->where('prestamos.tipo_item', 'libro')->where('prestamos.en_sala', true);
            } elseif ($tab === 'prestamos' && $request->query('tipo_prestamo') === 'recursos_apoyo') {
                $query->where('prestamos.tipo_item', '!=', 'libro');
            }

            foreach (['carrera' => 'carrera', 'anio_ingreso' => 'anio_ingreso', 'sexo' => 'sexo'] as $parametro => $columna) {
                if ($valor = $request->query($parametro)) {
                    $query->where("usuarios.{$columna}", $valor);
                }
            }

            // Quiénes (para acreditación se piden por separado): un tipo de usuario, docentes y
            // funcionarios juntos, o los que no están en el sistema (externos y visitas, en Ingresos).
            match ($request->query('tipo_usuario')) {
                'docente_funcionario' => $query->whereIn('usuarios.tipo', ['docente', 'funcionario']),
                'externo' => $query->whereNull('usuarios.id'),
                'estudiante', 'docente', 'funcionario' => $query->where('usuarios.tipo', $request->query('tipo_usuario')),
                default => null,
            };

            return $query;
        };

        // En Uso de salas, $base tiene una fila por integrante; $porReserva, una por reserva (las
        // que tienen al menos un integrante que pasa los filtros). En las demás pestañas son lo mismo.
        $porReserva = fn (QueryBuilder $filas) => $tab === 'logias'
            ? DB::table('reservas')->leftJoin('salas', 'salas.id', '=', 'reservas.sala_id')->whereIn('reservas.id', (clone $filas)->select('reservas.id'))
            : $filas;

        $base = $consulta($inicio, $fin);
        $registros = $porReserva($base);
        $total = (clone $registros)->count();
        $totalAnterior = $porReserva($consulta($inicioAnterior, $finAnterior))->count();

        // Cada sala con su código («L04 · Logia 04»), el mismo que se usa en el mesón.
        $salaConCodigo = "coalesce(salas.codigo || ' · ' || salas.nombre, 'Sin dato')";

        // Cuántos registros hubo por día y hora: con eso se arman la serie, las horas y el pico.
        $porDiaHora = (clone $registros)
            ->selectRaw("({$momento})::date as dia, extract(hour from {$momento})::int as hora, count(*) as cantidad")
            ->groupByRaw('1, 2')
            ->get();

        $tramos = Reportes::tramos($inicio, $fin, $periodo);
        $porTramo = [];
        $porHoraTotal = [];
        foreach ($porDiaHora as $fila) {
            $clave = Reportes::claveTramo(Carbon::parse($fila->dia)->setTime($fila->hora, 0), $periodo);
            $porTramo[$clave] = ($porTramo[$clave] ?? 0) + $fila->cantidad;
            $porHoraTotal[$fila->hora] = ($porHoraTotal[$fila->hora] ?? 0) + $fila->cantidad;
        }

        // Uso de salas: una reserva cuenta en todas las horas en que tuvo la sala, no solo en la de
        // inicio (la de 16:30 a 18:30 cuenta a las 16, 17 y 18). En el reporte de un día, la serie
        // también va por hora; en los demás, por día, y ahí cada reserva cuenta una vez.
        $reservasDeSala = collect();
        $porSalaHora = [];
        if ($tab === 'logias') {
            $reservasDeSala = (clone $registros)
                ->selectRaw("{$salaConCodigo} as sala, reservas.hora_inicio, reservas.hora_fin")
                ->get()
                ->map(fn ($r) => ['sala' => $r->sala, 'desde' => self::minutos($r->hora_inicio), 'hasta' => self::minutos($r->hora_fin)]);

            $porHoraTotal = [];
            foreach ($reservasDeSala as $r) {
                foreach (self::horasOcupadas($r['desde'], $r['hasta']) as $hora) {
                    $porHoraTotal[$hora] = ($porHoraTotal[$hora] ?? 0) + 1;
                    $porSalaHora[$r['sala']][$hora] = ($porSalaHora[$r['sala']][$hora] ?? 0) + 1;
                }
            }
            if ($periodo === 'dia') {
                $porTramo = collect($porHoraTotal)->mapWithKeys(fn ($cantidad, $hora) => [(string) $hora => $cantidad])->all();
            }
        }

        $serie = collect($tramos)->map(fn ($t) => [
            'label' => $t['label'],
            'value' => $porTramo[$t['clave']] ?? 0,
            'finDeSemana' => $t['finDeSemana'],
            'cerrado' => $t['cerrado'],
        ]);

        /** Cuántos hay de cada valor de la expresión, de más a menos (en Uso de salas, personas; con $registros, reservas). */
        $agrupar = fn (string $expresion, ?QueryBuilder $desde = null) => (clone ($desde ?? $base))
            ->selectRaw("{$expresion} as label, count(*) as value")
            ->groupByRaw($expresion)
            ->orderByDesc('value')
            ->orderBy('label')
            ->get()
            ->map(fn ($fila) => ['label' => (string) $fila->label, 'value' => (int) $fila->value])
            ->values();

        // Horas de atención: las de ese día si el período es un día; si no, las de toda la semana.
        $horas = $periodo === 'dia' ? HorarioBiblioteca::horasDelDia($inicio) : HorarioBiblioteca::horasDeLaSemana();
        $contarHoras = fn (array $porHora) => collect($horas)->map(fn ($hora) => [
            'label' => str_pad((string) $hora, 2, '0', STR_PAD_LEFT).'h',
            'value' => $porHora[$hora] ?? 0,
        ])->values();

        // Horario de mayor uso: en las salas, el tramo con más salas ocupadas a la vez (ver
        // tramoMasOcupado()); en lo demás, el bloque de dos horas seguidas con más registros.
        $horarioPico = null;
        if ($tab === 'logias') {
            $horarioPico = self::tramoMasOcupado($reservasDeSala, (int) config('salas.granularidad', 30));
        } else {
            foreach (array_slice($horas, 0, -1) as $hora) {
                $cantidad = ($porHoraTotal[$hora] ?? 0) + ($porHoraTotal[$hora + 1] ?? 0);
                if ($cantidad > 0 && (! $horarioPico || $cantidad > $horarioPico['value'])) {
                    $horarioPico = ['desde' => sprintf('%02d:00', $hora), 'hasta' => sprintf('%02d:00', $hora + 2), 'value' => $cantidad];
                }
            }
        }

        /** Lo mismo, solo con los préstamos del catálogo (no los recursos de apoyo). */
        $agruparMaterial = fn (string $expresion) => (clone $base)
            ->where('prestamos.tipo_item', 'libro')
            ->selectRaw("{$expresion} as label, count(*) as value")
            ->groupByRaw($expresion)
            ->orderByDesc('value')
            ->orderBy('label')
            ->get()
            ->map(fn ($fila) => ['label' => (string) $fila->label, 'value' => (int) $fila->value])
            ->values();
        $tipoDeMaterial = "coalesce(tm.nombre, 'Sin tipo de material')";

        // Quien no tiene carrera no es «Sin dato»: los docentes y funcionarios nunca la tienen (se los
        // agrupa por su unidad si está registrada), y «Sin dato» queda solo para quien no está registrado.
        $porCarrera = $this->unirVariantes($agrupar(<<<'SQL'
            case
                when usuarios.id is null then 'Sin dato (visitante externo)'
                when usuarios.carrera is not null then usuarios.carrera
                when usuarios.tipo = 'docente' then coalesce('Docentes · ' || nullif(usuarios.unidad, ''), 'Docentes (sin carrera)')
                when usuarios.tipo = 'funcionario' then coalesce('Funcionarios · ' || nullif(usuarios.unidad, ''), 'Funcionarios (sin carrera)')
                else 'Estudiantes sin carrera registrada'
            end
            SQL));

        $porHoraPorSala = [];
        $porSalaDetalle = [];
        if ($tab === 'logias') {
            $porHoraPorSala = collect($porSalaHora)
                ->map(fn ($porHora, $sala) => ['sala' => $sala, 'horas' => $contarHoras($porHora)])
                ->sortBy('sala')
                ->values();

            // El seguimiento de cada sala: cuántas reservas tuvo, a cuántas llegó el grupo, a
            // cuántas no, las horas que de verdad se usó (si devolvieron antes y se liberó el
            // resto, cuenta solo lo usado) y cuántos bloques se liberaron antes.
            $porSalaDetalle = (clone $registros)
                ->whereNotNull('salas.id')
                ->selectRaw(<<<'SQL'
                    salas.codigo, salas.nombre,
                    count(*) as reservas,
                    count(reservas.hora_prestamo_real) as presentes,
                    count(*) filter (where reservas.estado = 'no_show') as no_llegaron,
                    coalesce(sum(extract(epoch from (reservas.hora_fin - reservas.hora_inicio)) / 60) filter (where reservas.hora_prestamo_real is not null), 0) as minutos_uso,
                    count(reservas.hora_fin_reservada) as liberadas
                    SQL)
                ->groupBy('salas.codigo', 'salas.nombre')
                ->orderBy('salas.codigo')
                ->get()
                ->map(fn ($fila) => [
                    'codigo' => $fila->codigo,
                    'nombre' => $fila->nombre,
                    'reservas' => (int) $fila->reservas,
                    'presentes' => (int) $fila->presentes,
                    'no_llegaron' => (int) $fila->no_llegaron,
                    'minutos_uso' => (int) round((float) $fila->minutos_uso),
                    'liberadas' => (int) $fila->liberadas,
                ])
                ->values();
        }

        return response()->json([
            'rango' => ['desde' => $inicio->toDateString(), 'hasta' => $fin->toDateString()],
            'total' => $total,
            // Uso de salas: cuántas personas (integrantes) hubo en esas reservas; los desgloses por
            // carrera, tipo, sexo y año de ingreso suman esto. En las demás pestañas, igual al total.
            'personas' => $tab === 'logias' ? (clone $base)->count() : $total,
            'totalAnterior' => $totalAnterior,
            'promedioPorPeriodo' => (int) round($total / max(1, count($tramos))),
            'categoriaMasFrecuente' => $porCarrera->first()['label'] ?? '—',
            'horarioPico' => $horarioPico,
            'horario' => HorarioBiblioteca::semana(),
            'serie' => $serie,
            'porCarrera' => $porCarrera,
            'porSexo' => $agrupar("coalesce(usuarios.sexo, 'Sin dato')"),
            'porAnioIngreso' => $agrupar("coalesce(usuarios.anio_ingreso::text, 'Sin dato')")->sortBy('label')->values(),
            'porTipoUsuario' => $agrupar("coalesce(usuarios.tipo, 'Sin dato')"),
            'porHora' => $contarHoras($porHoraTotal),
            // Solo tienen sentido en su pestaña; en el resto van vacíos.
            // bibliografico (de la colección, a domicilio), en_sala (de la colección, leído en la
            // biblioteca) o recursos_apoyo (notebook, bicicleta…), y el recurso.
            'porTipoPrestamo' => $tab === 'prestamos' ? $agrupar("case when prestamos.tipo_item <> 'libro' then 'recursos_apoyo' when prestamos.en_sala then 'en_sala' else 'bibliografico' end") : [],
            // Lo del catálogo va por su tipo de material (Libro, Tesis, Juego educativo…); los
            // recursos de apoyo, por la clave de su categoría.
            'porRecurso' => $tab === 'prestamos' ? $agrupar("case when prestamos.tipo_item = 'libro' then {$tipoDeMaterial} else prestamos.tipo_item end") : [],
            'porTipoMaterial' => in_array($tab, ['prestamos', 'libros'], true) ? $agruparMaterial($tipoDeMaterial) : [],
            'porSala' => $tab === 'logias' ? $agrupar($salaConCodigo, $registros) : [],
            'porSalaDetalle' => $porSalaDetalle,
            // Título guardado en el préstamo (no la relación viva): el ranking sigue siendo
            // correcto aunque la copia ya no exista o el libro haya cambiado de título. En
            // Préstamos, solo el material de la colección (sin los recursos de apoyo).
            'porLibro' => in_array($tab, ['prestamos', 'libros'], true) ? $agruparMaterial("coalesce(prestamos.libro_titulo, '')") : [],
            'porHoraPorSala' => $porHoraPorSala,
        ]);
    }

    /** «16:30:00» → 990. */
    private static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    /** Las horas del reloj que toca una reserva: de 16:30 a 18:30, las 16, 17 y 18. */
    private static function horasOcupadas(int $desde, int $hasta): array
    {
        return $hasta > $desde ? range(intdiv($desde, 60), intdiv($hasta - 1, 60)) : [];
    }

    /**
     * Horario de mayor uso de las salas: el tramo, de $paso en $paso minutos (la grilla de las
     * reservas), con más salas ocupadas a la vez, sumando todos los días del período. Los tramos
     * seguidos con ese máximo se juntan; si quedan varios separados, gana el más largo y, si
     * empatan, el primero. value es cuántas reservas tocan ese horario.
     *
     * @param  Collection<int, array{sala: string, desde: int, hasta: int}>  $reservas  minutos desde medianoche
     * @return array{desde: string, hasta: string, value: int}|null
     */
    private static function tramoMasOcupado(Collection $reservas, int $paso): ?array
    {
        $ocupadas = [];
        foreach ($reservas as $r) {
            for ($m = intdiv($r['desde'], $paso) * $paso; $m < $r['hasta']; $m += $paso) {
                $ocupadas[$m] = ($ocupadas[$m] ?? 0) + 1;
            }
        }
        if (! $ocupadas) {
            return null;
        }

        ksort($ocupadas);
        $maximo = max($ocupadas);
        $tramos = [];
        foreach ($ocupadas as $m => $cantidad) {
            if ($cantidad !== $maximo) {
                continue;
            }
            $ultimo = array_key_last($tramos);
            if ($ultimo !== null && $tramos[$ultimo][1] === $m) {
                $tramos[$ultimo][1] = $m + $paso;
            } else {
                $tramos[] = [$m, $m + $paso];
            }
        }

        [$desde, $hasta] = collect($tramos)->sortBy([
            fn ($a, $b) => ($b[1] - $b[0]) <=> ($a[1] - $a[0]),
            fn ($a, $b) => $a[0] <=> $b[0],
        ])->first();
        $hhmm = fn (int $minutos) => sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);

        return [
            'desde' => $hhmm($desde),
            'hasta' => $hhmm($hasta),
            'value' => $reservas->filter(fn ($r) => $r['desde'] < $hasta && $r['hasta'] > $desde)->count(),
        ];
    }

    /**
     * Junta las carreras que solo se escriben distinto («DERECHO» y «Derecho», con o sin
     * tilde) y las muestra con un solo nombre: la variante que ya viene bien escrita o, si
     * todas vienen en mayúsculas, ese nombre en minúsculas con la inicial en mayúscula.
     *
     * @param  Collection<int, array{label: string, value: int}>  $filas
     * @return Collection<int, array{label: string, value: int}>
     */
    private function unirVariantes(Collection $filas): Collection
    {
        return $filas
            ->groupBy(fn (array $fila) => Texto::clave($fila['label']))
            ->map(function (Collection $variantes) {
                $mejor = $variantes->sortByDesc('value')->first();
                $conMinusculas = $variantes->first(fn (array $v) => preg_match('/\p{Ll}/u', $v['label']));

                return [
                    'label' => $conMinusculas['label'] ?? Texto::nombrePropio($mejor['label']),
                    'value' => (int) $variantes->sum('value'),
                ];
            })
            ->sortBy([['value', 'desc'], ['label', 'asc']])
            ->values();
    }
}
