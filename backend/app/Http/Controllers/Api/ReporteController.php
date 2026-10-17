<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaRecurso;
use App\Models\Usuario;
use App\Support\HorarioBiblioteca;
use App\Support\Reportes;
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
            'recursos' => ['libro' => 'Libros', ...CategoriaRecurso::orderBy('nombre')->pluck('nombre_plural', 'clave')->all()],
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
            $query = DB::table($tabla)->leftJoin('usuarios', 'usuarios.id', '=', "{$tabla}.usuario_id");

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
            } elseif ($tab === 'prestamos' && $request->query('tipo_prestamo') === 'recursos_apoyo') {
                $query->where('prestamos.tipo_item', '!=', 'libro');
            }

            foreach (['carrera' => 'carrera', 'anio_ingreso' => 'anio_ingreso', 'sexo' => 'sexo', 'tipo_usuario' => 'tipo'] as $parametro => $columna) {
                if ($valor = $request->query($parametro)) {
                    $query->where("usuarios.{$columna}", $valor);
                }
            }

            return $query;
        };

        $base = $consulta($inicio, $fin);
        $total = (clone $base)->count();
        $totalAnterior = $consulta($inicioAnterior, $finAnterior)->count();

        // Cuántos registros hubo por día y hora: con eso se arman la serie, las horas y el pico.
        $porDiaHora = (clone $base)
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

        $serie = collect($tramos)->map(fn ($t) => [
            'label' => $t['label'],
            'value' => $porTramo[$t['clave']] ?? 0,
            'finDeSemana' => $t['finDeSemana'],
            'cerrado' => $t['cerrado'],
        ]);

        /** Cuántos hay de cada valor de la expresión, de más a menos. */
        $agrupar = fn (string $expresion) => (clone $base)
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

        // Horario de mayor uso: el bloque de dos horas seguidas con más registros.
        $horarioPico = null;
        foreach (array_slice($horas, 0, -1) as $hora) {
            $cantidad = ($porHoraTotal[$hora] ?? 0) + ($porHoraTotal[$hora + 1] ?? 0);
            if ($cantidad > 0 && (! $horarioPico || $cantidad > $horarioPico['value'])) {
                $horarioPico = ['desde' => sprintf('%02d:00', $hora), 'hasta' => sprintf('%02d:00', $hora + 2), 'value' => $cantidad];
            }
        }

        $porCarrera = $agrupar("coalesce(usuarios.carrera, 'Sin dato')");

        $porHoraPorSala = [];
        if ($tab === 'logias') {
            $porSalaYHora = (clone $base)
                ->selectRaw("coalesce(salas.nombre, 'Sin dato') as sala, extract(hour from {$momento})::int as hora, count(*) as cantidad")
                ->groupByRaw('1, 2')
                ->get()
                ->groupBy('sala');
            $porHoraPorSala = $porSalaYHora
                ->map(fn ($filas, $sala) => ['sala' => $sala, 'horas' => $contarHoras($filas->pluck('cantidad', 'hora')->all())])
                ->sortBy('sala')
                ->values();
        }

        return response()->json([
            'rango' => ['desde' => $inicio->toDateString(), 'hasta' => $fin->toDateString()],
            'total' => $total,
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
            // bibliografico (libro) o recursos_apoyo (notebook, bicicleta…), y el recurso.
            'porTipoPrestamo' => $tab === 'prestamos' ? $agrupar("case when prestamos.tipo_item = 'libro' then 'bibliografico' else 'recursos_apoyo' end") : [],
            'porRecurso' => $tab === 'prestamos' ? $agrupar('prestamos.tipo_item') : [],
            'porSala' => $tab === 'logias' ? $agrupar("coalesce(salas.nombre, 'Sin dato')") : [],
            // Título guardado en el préstamo (no la relación viva): el ranking sigue siendo
            // correcto aunque la copia ya no exista o el libro haya cambiado de título.
            'porLibro' => $tab === 'libros' ? $agrupar("coalesce(prestamos.libro_titulo, '')") : [],
            'porHoraPorSala' => $porHoraPorSala,
        ]);
    }
}
