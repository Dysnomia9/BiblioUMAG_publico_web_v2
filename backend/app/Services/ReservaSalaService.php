<?php

namespace App\Services;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\HorarioBiblioteca;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservaSalaService
{
    /**
     * Registra el escaneo de un código de barras de logia (Horizon): la primera vez marca
     * la entrega de la reserva vigente para ese bloque horario, la segunda marca la
     * devolución. No crea reservas  solo cierra el ciclo de una reserva ya existente.
     *
     * Si la llave de un grupo de hoy sigue afuera, el escaneo es su devolución aunque su
     * horario ya haya terminado: es el grupo que vuelve tarde (y el mesón ve el aviso de
     * atraso), y la llave tiene que volver antes de entregársela al grupo siguiente.
     */
    public function escanearLogia(string $codigoBarras, Staff $staff): Reserva
    {
        $sala = Sala::where('codigo_barras', $codigoBarras)->where('tipo', 'logia')->first();

        if (! $sala) {
            throw new \RuntimeException('Código de barras no corresponde a ninguna logia');
        }

        $ahora = now();
        $deHoy = fn () => Reserva::where('sala_id', $sala->id)
            ->where('fecha', $ahora->toDateString())
            ->where('estado', 'activa');

        $llaveAfuera = $deHoy()->whereNotNull('hora_prestamo_real')->whereNull('hora_devolucion_real')->orderBy('hora_inicio')->first();
        if ($llaveAfuera) {
            $llaveAfuera->update([
                'devuelto_por' => $staff->nombre,
                'devuelto_por_staff_id' => $staff->id,
                'hora_devolucion_real' => $ahora,
                'estado' => 'finalizada',
            ]);

            return $llaveAfuera->fresh();
        }

        $reserva = $deHoy()
            ->where('hora_inicio', '<=', $ahora->format('H:i:s'))
            ->where('hora_fin', '>', $ahora->format('H:i:s'))
            ->first();

        if (! $reserva) {
            throw new \RuntimeException('Esta logia no tiene una reserva vigente en este momento');
        }

        if ($reserva->hora_prestamo_real) {
            throw new \RuntimeException('Esta reserva ya fue entregada y devuelta');
        }

        $reserva->update([
            'prestado_por' => $staff->nombre,
            'prestado_por_staff_id' => $staff->id,
            'hora_prestamo_real' => $ahora,
            'via' => 'BC',
        ]);

        return $reserva->fresh();
    }

    /**
     * Confirma manualmente (desde el panel de personal, sin escaneo de código de barras)
     * que la llave de una logia fue devuelta — equivalente a la segunda mitad de
     * escanearLogia(), pero identificando la reserva directamente por id en vez de por
     * código de barras + bloque horario vigente. A diferencia de cancelar (que elimina la
     * reserva), esto conserva el registro con quién y cuándo se devolvió la llave.
     */
    public function registrarDevolucion(Reserva $reserva, Staff $staff): Reserva
    {
        if ($reserva->estado !== 'activa') {
            throw new \RuntimeException('Esta reserva no está activa (fue cancelada, finalizada, o no se presentaron a tiempo)');
        }

        if ($reserva->hora_devolucion_real) {
            throw new \RuntimeException('Esta reserva ya tiene registrada su devolución');
        }

        $reserva->update([
            'devuelto_por' => $staff->nombre,
            'devuelto_por_staff_id' => $staff->id,
            'hora_devolucion_real' => now(),
            'estado' => 'finalizada',
        ]);

        return $reserva->fresh();
    }

    /**
     * El grupo devolvió la llave antes de que terminara su horario: lo que queda del bloque,
     * para que el mesón decida si lo libera (liberarResto()). Null si quedan menos de
     * config('salas.minimo_para_liberar') minutos, si ya se liberó o si la reserva no está
     * devuelta hoy. Va también en cada reserva del día y del listado, para que el detalle
     * ofrezca liberarlo después (si el aviso se cerró o la devolución se hizo sin él).
     *
     * @return array{reserva_id: int, sala: string, devuelta: string, hora_fin: string, minutos: int}|null
     */
    public static function tiempoSinUsar(Reserva $reserva): ?array
    {
        if ($reserva->estado !== 'finalizada' || ! $reserva->hora_devolucion_real || $reserva->hora_fin_reservada || ! $reserva->fecha->isToday()) {
            return null;
        }

        $termino = Carbon::parse($reserva->fecha->toDateString().' '.$reserva->hora_fin);
        $minutos = (int) floor(now()->diffInMinutes($termino));
        if ($minutos < config('salas.minimo_para_liberar', 5)) {
            return null;
        }

        $reserva->loadMissing('sala:id,nombre');

        return [
            'reserva_id' => $reserva->id,
            'sala' => $reserva->sala?->nombre ?? 'Sala',
            'devuelta' => $reserva->hora_devolucion_real->format('H:i'),
            'hora_fin' => substr($reserva->hora_fin, 0, 5),
            'minutos' => $minutos,
        ];
    }

    /**
     * Libera lo que quedaba del bloque de una reserva devuelta antes de tiempo: la reserva
     * pasa a terminar a la hora en que volvió la llave y la de término original queda en
     * hora_fin_reservada. Así la sala se ve libre y se puede volver a reservar (o «Reservar
     * ahora») en ese rato, y la cuota diaria del grupo cuenta solo lo que usó.
     */
    public function liberarResto(Reserva $reserva): Reserva
    {
        if (! self::tiempoSinUsar($reserva)) {
            throw new \RuntimeException('Esta reserva no tiene tiempo sin usar que liberar: la llave no se ha devuelto, su horario ya terminó o ya se liberó.');
        }

        // Al minuto (con los segundos, «Reservar ahora» en ese mismo minuto chocaría con ella) y
        // nunca antes de su inicio, para que no quede una reserva de duración cero.
        $inicio = Carbon::parse($reserva->fecha->toDateString().' '.$reserva->hora_inicio);
        $fin = $reserva->hora_devolucion_real->copy()->startOfMinute()->max($inicio->addMinute());

        $reserva->update(['hora_fin_reservada' => $reserva->hora_fin, 'hora_fin' => $fin->format('H:i:s')]);

        return $reserva->fresh();
    }

    /**
     * Confirma que el grupo se presentó a retirar la sala (check-in manual, sin código de
     * barras — sirve tanto para logias como para las salas con nombre propio que no
     * tienen codigo_barras). Rechaza la confirmación si ya pasó el plazo de 15 minutos
     * (ver Reserva::plazoConfirmacion()) y en ese caso libera la reserva de una vez
     * (mismo efecto que liberarPorNoPresentacion()) para que quede disponible de inmediato.
     */
    public function registrarLlegada(Reserva $reserva, Staff $staff): Reserva
    {
        if ($reserva->estado !== 'activa') {
            throw new \RuntimeException('Esta reserva no está activa (fue cancelada, finalizada, o no se presentaron a tiempo)');
        }

        if ($reserva->hora_prestamo_real) {
            throw new \RuntimeException('Esta reserva ya tiene registrada su llegada');
        }

        if ($reserva->estaVencidaSinConfirmar()) {
            $reserva->update(['estado' => 'no_show']);
            throw new \RuntimeException('El plazo de 15 minutos para confirmar esta reserva ya venció — la sala quedó liberada');
        }

        // Como en escanearLogia(), que solo encuentra la reserva vigente de hoy: desde el
        // detalle de otro día no se puede dar por llegado a un grupo que viene mañana.
        if (! $reserva->fecha->isToday()) {
            throw new \RuntimeException('Esta reserva es para el '.$reserva->fecha->format('d/m/Y').': su llegada se confirma ese día');
        }

        $reserva->update([
            'prestado_por' => $staff->nombre,
            'prestado_por_staff_id' => $staff->id,
            'hora_prestamo_real' => now(),
            'via' => 'manual',
        ]);

        return $reserva->fresh();
    }

    /**
     * Acción manual del staff para liberar de inmediato una reserva vencida sin
     * confirmar, sin esperar a que alguien más intente reservar ese mismo bloque (que
     * es cuando existeSolapamiento() la liberaría de todos modos, de forma perezosa).
     */
    public function liberarPorNoPresentacion(Reserva $reserva): Reserva
    {
        if (! $reserva->estaVencidaSinConfirmar()) {
            throw new \RuntimeException('Esta reserva todavía está dentro del plazo de confirmación');
        }

        $reserva->update(['estado' => 'no_show']);

        return $reserva->fresh();
    }

    /** Si la reserva está vencida sin confirmar, la marca 'no_show' (libera el bloque) y devuelve true. */
    public function liberarSiVencida(Reserva $reserva): bool
    {
        if (! $reserva->estaVencidaSinConfirmar()) {
            return false;
        }

        $reserva->update(['estado' => 'no_show']);

        return true;
    }

    /**
     * Libera de una vez todas las reservas vencidas sin confirmar. La liberación es
     * perezosa (ocurre cuando alguien mira ese día), y el Listado de reservas abarca
     * todos los días: sin esto, una reserva de la semana pasada a la que nadie llegó
     * seguiría figurando como pendiente de llegada.
     */
    public function liberarVencidas(): int
    {
        return Reserva::where('estado', 'activa')
            ->whereNull('hora_prestamo_real')
            ->where('fecha', '<=', now()->toDateString())
            ->get()
            ->filter(fn (Reserva $r) => $this->liberarSiVencida($r))
            ->count();
    }

    /**
     * Valida el tramo horario (ventana de atención, duración, granularidad) y, si
     * $inmediata es true, sobrescribe `hora_inicio` en $data con la hora real del
     * servidor — para que la hora de "Reservar ahora" la fije el backend, no el
     * navegador del usuario. Normaliza hora_inicio/hora_fin a H:i:s al terminar.
     * Devuelve el mensaje de error si algo no cumple, o null si el tramo es válido.
     * Compartido entre SalaController::storeReserva(), SalaController::editarReserva()
     * y PortalController::reservarSala().
     *
     * Al editar ($editando), la hora de inicio o de término que la reserva ya tenía
     * (mismo día, misma hora) se acepta tal cual: pudo quedar en el pasado o fuera de la
     * grilla de 30 minutos ("Reservar ahora"), y eso no impide cambiarle la sala o el
     * otro extremo del horario.
     */
    public function validarTramo(array &$data, bool $inmediata, bool $permitirHoraPasada = false, ?Reserva $editando = null): ?string
    {
        // Las salas están dentro de la biblioteca: solo se usan mientras atiende (config/biblioteca.php).
        $dia = Carbon::parse($data['fecha']);
        $ventana = $this->ventanaDelDia($dia);
        if (! $ventana) {
            return 'La biblioteca no atiende los '.HorarioBiblioteca::diaEnPlural($dia).': elige otro día.';
        }

        if ($inmediata) {
            $data['hora_inicio'] = now()->format('H:i');
        }

        $horaInicio = Carbon::parse($data['hora_inicio']);
        $horaFin = Carbon::parse($data['hora_fin']);

        if ($horaFin->lessThanOrEqualTo($horaInicio)) {
            return 'La hora de término debe ser posterior a la hora de inicio.';
        }

        $mismoDia = $editando && $editando->fecha->toDateString() === $data['fecha'];
        $inicioSinCambios = $mismoDia && substr($editando->hora_inicio, 0, 5) === $horaInicio->format('H:i');
        $finSinCambios = $mismoDia && substr($editando->hora_fin, 0, 5) === $horaFin->format('H:i');

        // Fuera de 'inmediata' (que siempre usa la hora real del servidor, nunca
        // queda en el pasado), por defecto no se puede agendar una hora que ya pasó
        // hoy — salvo que el caller pase $permitirHoraPasada=true (solo el staff con
        // rol admin, ver SalaController::storeReserva()). El portal nunca pasa esto
        // en true: un usuario del portal jamás puede agendar en el pasado.
        if (! $inmediata && ! $permitirHoraPasada && ! $inicioSinCambios && $data['fecha'] === now()->toDateString() && $horaInicio->format('H:i') < now()->format('H:i')) {
            return 'Esa hora ya pasó — elige un horario desde ahora en adelante.';
        }

        [$apertura, $cierre] = $ventana;
        if ($horaInicio->format('H:i') < $apertura || $horaFin->format('H:i') > $cierre) {
            return 'Los '.HorarioBiblioteca::diaEnPlural($dia)." la biblioteca atiende de {$apertura} a {$cierre}: elige un horario dentro de ese rango.";
        }

        $duracion = $horaInicio->diffInMinutes($horaFin);
        $minima = config('salas.duracion_minima');
        $maxima = config('salas.duracion_maxima');

        if ($duracion < $minima || $duracion > $maxima) {
            return "La reserva debe durar entre {$minima} minutos y ".($maxima / 60).' horas.';
        }

        $granularidad = config('salas.granularidad');
        $inicioFueraDeGrilla = $horaInicio->minute % $granularidad !== 0 && ! $inicioSinCambios;
        $finFueraDeGrilla = $horaFin->minute % $granularidad !== 0 && ! $finSinCambios;
        if (! $inmediata && ($inicioFueraDeGrilla || $finFueraDeGrilla)) {
            return 'El inicio y el término deben caer en una hora en punto o media.';
        }

        $data['hora_inicio'] = $horaInicio->format('H:i:s');
        $data['hora_fin'] = $horaFin->format('H:i:s');

        return null;
    }

    /**
     * Solo 'activa'/'finalizada' bloquean un tramo nuevo — 'no_show' y 'cancelada'
     * (ambas terminales, la sala nunca se usó o se liberó a propósito) no cuentan.
     * Antes esta query no filtraba por estado en absoluto y dependía de que
     * liberarSiVencida() recién convirtiera una 'activa' vencida a 'no_show' EN ESE
     * MISMO request — una fila ya 'no_show' de un request anterior seguía bloqueando
     * para siempre. Con 'cancelada' persistiendo la fila (soft delete, ver
     * SalaController::destroyReserva()) ese mismo bug habría bloqueado cualquier
     * tramo recién cancelado — de ahí el filtro explícito.
     */
    public function existeSolapamiento(int $salaId, string $fecha, string $horaInicio, string $horaFin, ?int $ignorarReservaId = null): bool
    {
        $horaInicio = Carbon::parse($horaInicio)->format('H:i:s');
        $horaFin = Carbon::parse($horaFin)->format('H:i:s');

        $candidatas = Reserva::where('sala_id', $salaId)
            ->where('fecha', $fecha)
            ->where('hora_inicio', '<', $horaFin)
            ->where('hora_fin', '>', $horaInicio)
            ->whereIn('estado', ['activa', 'finalizada'])
            ->when($ignorarReservaId, fn ($query) => $query->where('id', '!=', $ignorarReservaId))
            ->get();

        foreach ($candidatas as $candidata) {
            if (! $this->liberarSiVencida($candidata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revisa y guarda una reserva nueva o cambiada con la sala y los integrantes
     * bloqueados (SELECT ... FOR UPDATE), en una sola transacción. Sin esto, dos
     * reservas que llegaban al mismo tiempo para la misma sala, o con una misma persona,
     * pasaban las dos las revisiones (choque de horario, reserva pendiente única, cuota
     * diaria) antes de que la otra se guardara. Una restricción de exclusión de
     * PostgreSQL cubriría solo el choque en la sala, no las reglas por persona. Ahora la
     * segunda espera a que termine la primera y, como en PostgreSQL cada consulta ve lo
     * último confirmado, sus revisiones ya incluyen la reserva de la otra.
     *
     * Los bloqueos van antes que las revisiones, y siempre la sala primero y los
     * usuarios por id, para que dos reservas no queden esperándose entre sí.
     *
     * @template T
     *
     * @param  iterable<int>  $usuarioIds
     * @param  \Closure(): T  $accion
     * @return T
     */
    public function conSalaEIntegrantesBloqueados(?int $salaId, iterable $usuarioIds, \Closure $accion): mixed
    {
        return DB::transaction(function () use ($salaId, $usuarioIds, $accion) {
            if ($salaId) {
                Sala::whereKey($salaId)->lockForUpdate()->first();
            }
            Usuario::whereKey(collect($usuarioIds)->sort()->values())->orderBy('id')->lockForUpdate()->get();

            return $accion();
        });
    }

    /**
     * Cuota diaria de minutos por participante (config('salas.cuota_diaria'), 240 por
     * defecto — reemplaza la vieja regla de "máximo 2 bloques adyacentes en la misma
     * sala": con duración libre, adyacencia/misma-sala dejan de tener sentido, lo que
     * importa es el total de minutos reservados ese día, en cualquier sala. Cuentan las
     * reservas 'activa' y 'finalizada'; 'no_show' no consume cuota. Devuelve
     * `[rut, minutosYaUsados]` del primer participante que excedería la cuota al sumar
     * la reserva solicitada, o null si todos caben.
     *
     * @return array{0: string, 1: int}|null
     */
    public function participanteExcedeCuotaDiaria(array $ruts, string $fecha, string $horaInicio, string $horaFin, ?int $ignorarReservaId = null): ?array
    {
        $idPorRut = Usuario::whereIn('rut', $ruts)->pluck('id', 'rut');
        $duracionSolicitada = Carbon::parse($horaInicio)->diffInMinutes(Carbon::parse($horaFin));
        $cuota = config('salas.cuota_diaria', 240);

        foreach ($ruts as $rut) {
            $usuarioId = $idPorRut->get($rut);
            if (! $usuarioId) {
                continue; // RUT inválido — ya lo rechaza la regla 'exists' del validator.
            }

            $minutosUsados = Reserva::where('fecha', $fecha)
                ->whereIn('estado', ['activa', 'finalizada'])
                ->whereHas('participantes', fn ($q) => $q->where('usuarios.id', $usuarioId))
                ->when($ignorarReservaId, fn ($q) => $q->where('id', '!=', $ignorarReservaId))
                ->get()
                ->reject(fn (Reserva $r) => $r->estado === 'activa' && $this->liberarSiVencida($r))
                ->sum(fn (Reserva $r) => $r->duracionMinutos());

            if ($minutosUsados + $duracionSolicitada > $cuota) {
                return [$rut, (int) $minutosUsados];
            }
        }

        return null;
    }

    /**
     * Arma el mensaje 409 para participanteExcedeCuotaDiaria() — en 2ª persona
     * ("Ya tienes...") si el RUT que excedió la cuota es el del propio usuario
     * autenticado (portal), o en 3ª persona ("El RUT X ya tiene...") en cualquier
     * otro caso (staff reservando para un grupo). Mismo criterio que el mensaje de
     * participanteConReservaSolapada en PortalController::reservarSala().
     *
     * @param array{0: string, 1: int} $excedeCuota
     */
    public function mensajeCuotaExcedida(array $excedeCuota, ?string $rutPropio = null): string
    {
        [$rut, $minutosUsados] = $excedeCuota;
        $maximo = $this->formatearMinutos(config('salas.cuota_diaria', 240));
        $usados = $this->formatearMinutos($minutosUsados);

        if ($rut === $rutPropio) {
            return "Ya tienes {$usados} reservados hoy; el máximo diario es de {$maximo}.";
        }

        return "El RUT {$rut} ya tiene {$usados} reservados hoy; el máximo diario es de {$maximo}.";
    }

    /**
     * Ventana en que se reservan salas ese día: la de config/salas.php recortada al
     * horario de atención de la biblioteca. Null si ese día no atiende.
     *
     * @return array{0: string, 1: string}|null
     */
    public function ventanaDelDia(Carbon $fecha): ?array
    {
        $horario = HorarioBiblioteca::delDia($fecha);
        if (! $horario) {
            return null;
        }

        $apertura = max($horario[0], config('salas.apertura'));
        $cierre = min($horario[1], config('salas.cierre'));

        return $apertura < $cierre ? [$apertura, $cierre] : null;
    }

    /**
     * Lo que abarca la línea de tiempo de salas: desde la apertura más temprana hasta el
     * cierre más tardío de la semana. Lo que queda fuera del horario de cada día se
     * muestra rayado.
     *
     * @return array{0: string, 1: string}
     */
    public function rangoDeLaSemana(): array
    {
        $abiertos = array_filter(HorarioBiblioteca::semana());
        if (! $abiertos) {
            return [config('salas.apertura'), config('salas.cierre')];
        }

        return [
            max(min(array_column($abiertos, 0)), config('salas.apertura')),
            min(max(array_column($abiertos, 1)), config('salas.cierre')),
        ];
    }

    private function formatearMinutos(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        if ($horas === 0) {
            return "{$resto} min";
        }

        return $resto === 0 ? "{$horas} h" : "{$horas} h {$resto} min";
    }

    /**
     * Minutos libres en la sala desde $desde (formato H:i o H:i:s) ese día, acotados
     * por el cierre de ese día y por duracion_maxima — usado para "Reservar ahora" y para
     * avisar en el frontend cuánto tiempo real queda disponible antes de que choque con
     * la siguiente reserva. Devuelve 0 si la sala está ocupada justo en ese instante o si
     * a esa hora la biblioteca no atiende.
     */
    public function duracionMaximaDisponible(int $salaId, string $fecha, string $desde): int
    {
        $ventana = $this->ventanaDelDia(Carbon::parse($fecha));
        $desde = Carbon::parse($desde);
        $maxima = config('salas.duracion_maxima', 120);

        if (! $ventana || $desde->format('H:i') < $ventana[0] || $desde->format('H:i') >= $ventana[1]) {
            return 0;
        }
        $cierre = Carbon::parse($ventana[1]);

        $ocupadaAhora = Reserva::where('sala_id', $salaId)
            ->where('fecha', $fecha)
            ->whereNotIn('estado', ['no_show', 'cancelada'])
            ->where('hora_inicio', '<=', $desde->format('H:i:s'))
            ->where('hora_fin', '>', $desde->format('H:i:s'))
            ->get()
            ->reject(fn (Reserva $r) => $r->estado === 'activa' && $this->liberarSiVencida($r));

        if ($ocupadaAhora->isNotEmpty()) {
            return 0;
        }

        $siguiente = Reserva::where('sala_id', $salaId)
            ->where('fecha', $fecha)
            ->whereNotIn('estado', ['no_show', 'cancelada'])
            ->where('hora_inicio', '>=', $desde->format('H:i:s'))
            ->get()
            ->reject(fn (Reserva $r) => $r->estado === 'activa' && $this->liberarSiVencida($r))
            ->sortBy('hora_inicio')
            ->first();

        $limite = $siguiente ? Carbon::parse($siguiente->hora_inicio) : $cierre;
        $minutosLibres = $desde->diffInMinutes($limite);

        return max(0, min($minutosLibres, $maxima));
    }

    /**
     * Arma la vista de un día para el módulo de salas: por cada sala, sus tramos
     * reservados (ordenados por hora) más disponibilidad "ahora mismo" si $fecha es
     * hoy. Compartido entre SalaController::index() (staff) y PortalController::
     * salas() (portal) para no duplicar el armado del JSON en los dos lados.
     */
    public function vistaDelDia(Collection $salas, string $fecha): Collection
    {
        $reservas = Reserva::with('participantes:id,nombre,apellido,rut')
            ->where('fecha', $fecha)
            ->whereNotIn('estado', ['no_show', 'cancelada'])
            ->get()
            ->reject(fn (Reserva $r) => $this->liberarSiVencida($r))
            ->values();

        $esHoy = $fecha === now()->toDateString();
        $ahora = now()->format('H:i:s');

        $tramosPorSala = $reservas->groupBy('sala_id')->map(
            fn ($grupo) => $grupo->map(fn (Reserva $r) => [
                'reserva_id' => $r->id,
                'hora_inicio' => substr($r->hora_inicio, 0, 5),
                'hora_fin' => substr($r->hora_fin, 0, 5),
                'hora_fin_reservada' => $r->hora_fin_reservada ? substr($r->hora_fin_reservada, 0, 5) : null,
                'estado' => $r->estado,
                'cantidad_personas' => $r->cantidad_personas,
                'rut_usuario' => $r->rut_usuario,
                'usuario_id' => $r->usuario_id,
                'prestado_por' => $r->prestado_por,
                'devuelto_por' => $r->devuelto_por,
                'hora_prestamo_real' => $r->hora_prestamo_real,
                'hora_devolucion_real' => $r->hora_devolucion_real,
                'plazo_confirmacion' => $r->plazoConfirmacion(),
                'vencida_sin_confirmar' => $r->estaVencidaSinConfirmar(),
                'tiempo_sin_usar' => self::tiempoSinUsar($r),
                'personas' => self::personas($r),
            ])->sortBy('hora_inicio')->values()
        );

        return $salas->map(function (Sala $sala) use ($tramosPorSala, $esHoy, $fecha, $ahora) {
            $disponibleHastaMin = $esHoy ? $this->duracionMaximaDisponible($sala->id, $fecha, $ahora) : null;

            return [
                'id' => $sala->id,
                'nombre' => $sala->nombre,
                'capacidad' => $sala->capacidad,
                'tipo' => $sala->tipo,
                'codigo_barras' => $sala->codigo_barras,
                'libre_ahora' => $esHoy ? $disponibleHastaMin > 0 : null,
                'disponible_hasta_min' => $disponibleHastaMin,
                'tramos' => $tramosPorSala->get($sala->id, collect())->values(),
            ];
        })->values();
    }

    /**
     * Integrantes de la reserva como los muestra el panel de salas.
     *
     * @return list<array{rut: string, nombre: string}>
     */
    public static function personas(Reserva $reserva): array
    {
        return $reserva->participantes
            ->map(fn (Usuario $u) => ['rut' => $u->rut, 'nombre' => "{$u->nombre} {$u->apellido}"])
            ->values()
            ->all();
    }

    /**
     * Una reserva como la muestra el Listado de reservas: la sala, el horario, quién
     * está a cargo, los integrantes, quién entregó y recibió la llave, y su situación.
     * Espera cargadas las relaciones sala y participantes.
     */
    public static function paraListado(Reserva $reserva): array
    {
        $titular = $reserva->participantes->firstWhere('id', $reserva->usuario_id) ?? $reserva->participantes->first();

        return [
            'id' => $reserva->id,
            'sala' => $reserva->sala?->only(['id', 'nombre', 'tipo']),
            'fecha' => $reserva->fecha->toDateString(),
            'hora_inicio' => substr($reserva->hora_inicio, 0, 5),
            'hora_fin' => substr($reserva->hora_fin, 0, 5),
            'hora_fin_reservada' => $reserva->hora_fin_reservada ? substr($reserva->hora_fin_reservada, 0, 5) : null,
            'duracion_minutos' => $reserva->duracionMinutos(),
            'estado' => $reserva->estado,
            'situacion' => self::situacion($reserva),
            'cantidad_personas' => $reserva->cantidad_personas,
            'titular' => [
                'rut' => $titular?->rut ?? $reserva->rut_usuario,
                'nombre' => $titular ? trim("{$titular->nombre} {$titular->apellido}") : null,
            ],
            'personas' => self::personas($reserva),
            'prestado_por' => $reserva->prestado_por,
            'devuelto_por' => $reserva->devuelto_por,
            'hora_prestamo_real' => $reserva->hora_prestamo_real,
            'hora_devolucion_real' => $reserva->hora_devolucion_real,
            'via' => $reserva->via,
            'plazo_confirmacion' => $reserva->plazoConfirmacion(),
            'tiempo_sin_usar' => self::tiempoSinUsar($reserva),
            'created_at' => $reserva->created_at,
        ];
    }

    /**
     * En qué está la reserva: 'pendiente' (activa, sin llegada), 'en_uso' (llegaron y
     * aún no termina su horario), 'sin_devolver' (ya terminó y nadie registró la
     * devolución de la llave), o su estado final: 'finalizada', 'no_show', 'cancelada'.
     */
    public static function situacion(Reserva $reserva): string
    {
        if ($reserva->estado !== 'activa') {
            return $reserva->estado;
        }

        if (! $reserva->hora_prestamo_real) {
            return 'pendiente';
        }

        $termino = Carbon::parse($reserva->fecha->toDateString().' '.$reserva->hora_fin);

        return now()->greaterThan($termino) ? 'sin_devolver' : 'en_uso';
    }

    /**
     * Convierte lo escrito en cada casilla de integrante (RUT, pasaporte o DNI, con o
     * sin puntos y guion) en el identificador guardado de cada usuario. Los errores
     * van por casilla ("ruts.2"), igual que daba antes la regla 'exists:usuarios,rut',
     * para que el formulario marque en rojo la que corresponde. Solo se admiten
     * usuarios registrados: los visitantes externos no reservan salas.
     *
     * @param  array<int|string, string>  $valores
     * @return list<string>
     */
    public function identificadoresDeParticipantes(array $valores): array
    {
        $errores = [];
        $identificadores = [];
        $vistos = [];

        foreach ($valores as $i => $valor) {
            $usuario = Usuario::buscarPorIdentificador($valor);

            if (! $usuario) {
                $errores["ruts.{$i}"] = ['Uno de los RUT o documentos ingresados no corresponde a un usuario registrado en el sistema.'];
            } elseif (isset($vistos[$usuario->id])) {
                $errores["ruts.{$i}"] = ['No puedes ingresar el mismo RUT más de una vez en la misma reserva.'];
            } else {
                $vistos[$usuario->id] = true;
                $identificadores[] = $usuario->rut;
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $identificadores;
    }

    /**
     * Cada persona tiene a lo más una reserva pendiente (activa, sin llegada confirmada
     * y dentro de su plazo), de cualquier día y en cualquier sala: para tomar otra, usa
     * o cancela la que tiene. La que ya está en uso o terminó no cuenta. Devuelve
     * `[rut, reserva]` del primero que ya tiene una, o null si nadie.
     *
     * @return array{0: string, 1: Reserva}|null
     */
    public function participanteConReservaPendiente(array $ruts, ?int $ignorarReservaId = null): ?array
    {
        $idPorRut = Usuario::whereIn('rut', $ruts)->pluck('id', 'rut');

        $pendientes = Reserva::where('estado', 'activa')
            ->whereNull('hora_prestamo_real')
            ->when($ignorarReservaId, fn ($query) => $query->where('id', '!=', $ignorarReservaId))
            ->whereHas('participantes', fn ($q) => $q->whereIn('usuarios.id', $idPorRut->values()))
            ->with(['sala:id,nombre', 'participantes' => fn ($q) => $q->whereIn('usuarios.id', $idPorRut->values())])
            ->get()
            ->reject(fn (Reserva $r) => $this->liberarSiVencida($r));

        foreach ($ruts as $rut) {
            $reserva = $pendientes->first(fn (Reserva $r) => $r->participantes->contains('id', $idPorRut->get($rut)));
            if ($reserva) {
                return [$rut, $reserva];
            }
        }

        return null;
    }

    /**
     * Mensaje 409 de participanteConReservaPendiente(): en 2ª persona si es el propio
     * usuario del portal, en 3ª en cualquier otro caso (como mensajeCuotaExcedida()).
     * Con $detalleAjeno en false (el portal) no dice la sala ni el horario de la reserva
     * de otra persona: un estudiante no tiene por qué saber dónde estará otro.
     *
     * @param  array{0: string, 1: Reserva}  $pendiente
     */
    public function mensajeReservaPendiente(array $pendiente, ?string $rutPropio = null, bool $detalleAjeno = true): string
    {
        [$rut, $reserva] = $pendiente;

        if ($rut === $rutPropio) {
            return "Ya tienes una reserva pendiente ({$reserva->descripcion()}): úsala o cancélala antes de reservar otra sala.";
        }

        if (! $detalleAjeno) {
            return "El RUT {$rut} ya tiene una reserva pendiente: debe usarla o cancelarla antes de reservar otra sala.";
        }

        return "El RUT {$rut} ya tiene una reserva pendiente ({$reserva->descripcion()}): debe usarla o cancelarla antes de reservar otra sala.";
    }

    /**
     * Devuelve el primer RUT que ya participa en otra reserva (en cualquier sala)
     * cuyo horario se solape con el tramo solicitado, o null si ninguno choca.
     */
    public function participanteConReservaSolapada(array $ruts, string $fecha, string $horaInicio, string $horaFin, ?int $ignorarReservaId = null): ?string
    {
        $idPorRut = Usuario::whereIn('rut', $ruts)->pluck('id', 'rut');
        $horaInicio = Carbon::parse($horaInicio)->format('H:i:s');
        $horaFin = Carbon::parse($horaFin)->format('H:i:s');

        $idsConflicto = Reserva::where('fecha', $fecha)
            ->where('hora_inicio', '<', $horaFin)
            ->where('hora_fin', '>', $horaInicio)
            ->whereIn('estado', ['activa', 'finalizada'])
            ->when($ignorarReservaId, fn ($query) => $query->where('id', '!=', $ignorarReservaId))
            ->whereHas('participantes', fn ($q) => $q->whereIn('usuarios.id', $idPorRut->values()))
            ->with(['participantes' => fn ($q) => $q->whereIn('usuarios.id', $idPorRut->values())])
            ->get()
            ->reject(fn (Reserva $r) => $this->liberarSiVencida($r))
            ->flatMap(fn ($r) => $r->participantes->pluck('id'))
            ->unique();

        foreach ($ruts as $rut) {
            if ($idsConflicto->contains($idPorRut->get($rut))) {
                return $rut;
            }
        }

        return null;
    }

    /**
     * Cancelación desde el portal de quien hizo la reserva (config/salas.php): las primeras
     * del día no tienen consecuencias, para corregir un error; desde ahí, cada una lo deja
     * unos minutos sin poder reservar otra sala. Solo se le cuenta a quien la hizo: si
     * cancela un integrante (quizás lo pusieron sin preguntarle), no le pesa a nadie.
     */
    public function registrarCancelacionDelPortal(Usuario $usuario): void
    {
        $clave = $this->claveCancelaciones($usuario);
        $cancelaciones = (int) Cache::get($clave, 0) + 1;
        Cache::put($clave, $cancelaciones, now()->endOfDay());

        $minutos = (int) config('salas.espera_tras_cancelar');
        if ($minutos > 0 && $cancelaciones > (int) config('salas.cancelaciones_sin_espera')) {
            $hasta = now()->addMinutes($minutos);
            Cache::put("salas.espera:{$usuario->id}", $hasta->getTimestamp(), $hasta);
        }
    }

    /** Hasta cuándo no puede reservar otra sala por haber cancelado, o null. */
    public function esperaParaReservar(Usuario $usuario): ?Carbon
    {
        $hasta = Cache::get("salas.espera:{$usuario->id}");

        return is_int($hasta) && $hasta > now()->getTimestamp() ? now()->setTimestamp($hasta) : null;
    }

    /** Cuántas reservas propias puede cancelar todavía hoy sin quedar esperando. */
    public function cancelacionesSinEsperaRestantes(Usuario $usuario): int
    {
        return max(0, (int) config('salas.cancelaciones_sin_espera') - (int) Cache::get($this->claveCancelaciones($usuario), 0));
    }

    public function mensajeEspera(Carbon $hasta): string
    {
        $minutos = (int) config('salas.espera_tras_cancelar');
        $libres = (int) config('salas.cancelaciones_sin_espera');
        $motivo = match (true) {
            $libres === 0 => 'Cancelaste una reserva hace poco.',
            $libres === 1 => 'Ya cancelaste más de una reserva hoy.',
            default => "Ya cancelaste más de {$libres} reservas hoy.",
        };

        return "{$motivo} Para evitar reservas y cancelaciones seguidas, hay que esperar {$minutos} minutos: podrás reservar una sala de nuevo a las {$hasta->format('H:i')}.";
    }

    private function claveCancelaciones(Usuario $usuario): string
    {
        return "salas.cancelaciones:{$usuario->id}:".now()->toDateString();
    }
}
