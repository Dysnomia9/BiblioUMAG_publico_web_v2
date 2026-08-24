<?php

namespace App\Services;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReservaSalaService
{
    /**
     * Registra el escaneo de un código de barras de logia (Horizon): la primera vez marca
     * la entrega de la reserva vigente para ese bloque horario, la segunda marca la
     * devolución. No crea reservas  solo cierra el ciclo de una reserva ya existente.
     */
    public function escanearLogia(string $codigoBarras, Staff $staff): Reserva
    {
        $sala = Sala::where('codigo_barras', $codigoBarras)->where('tipo', 'logia')->first();

        if (! $sala) {
            throw new \RuntimeException('Código de barras no corresponde a ninguna logia');
        }

        $ahora = now();
        $reserva = Reserva::where('sala_id', $sala->id)
            ->where('fecha', $ahora->toDateString())
            ->where('hora_inicio', '<=', $ahora->format('H:i:s'))
            ->where('hora_fin', '>', $ahora->format('H:i:s'))
            ->where('estado', 'activa')
            ->first();

        if (! $reserva) {
            throw new \RuntimeException('Esta logia no tiene una reserva vigente en este momento');
        }

        if (! $reserva->hora_prestamo_real) {
            $reserva->update([
                'prestado_por' => $staff->nombre,
                'prestado_por_staff_id' => $staff->id,
                'hora_prestamo_real' => $ahora,
                'via' => 'BC',
            ]);
        } elseif (! $reserva->hora_devolucion_real) {
            $reserva->update([
                'devuelto_por' => $staff->nombre,
                'devuelto_por_staff_id' => $staff->id,
                'hora_devolucion_real' => $ahora,
                'estado' => 'finalizada',
            ]);
        } else {
            throw new \RuntimeException('Esta reserva ya fue entregada y devuelta');
        }

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
     * Valida el tramo horario (ventana de atención, duración, granularidad) y, si
     * $inmediata es true, sobrescribe `hora_inicio` en $data con la hora real del
     * servidor — para que la hora de "Reservar ahora" la fije el backend, no el
     * navegador del usuario. Normaliza hora_inicio/hora_fin a H:i:s al terminar.
     * Devuelve el mensaje de error si algo no cumple, o null si el tramo es válido.
     * Compartido entre SalaController::storeReserva() y PortalController::
     * reservarSala() (ver convención 4 de CLAUDE.md).
     */
    public function validarTramo(array &$data, bool $inmediata, bool $permitirHoraPasada = false): ?string
    {
        if ($inmediata) {
            $data['hora_inicio'] = now()->format('H:i');
        }

        $horaInicio = Carbon::parse($data['hora_inicio']);
        $horaFin = Carbon::parse($data['hora_fin']);

        if ($horaFin->lessThanOrEqualTo($horaInicio)) {
            return 'La hora de término debe ser posterior a la hora de inicio.';
        }

        // Fuera de 'inmediata' (que siempre usa la hora real del servidor, nunca
        // queda en el pasado), por defecto no se puede agendar una hora que ya pasó
        // hoy — salvo que el caller pase $permitirHoraPasada=true (solo el staff con
        // rol admin, ver SalaController::storeReserva()). El portal nunca pasa esto
        // en true: un usuario del portal jamás puede agendar en el pasado.
        if (! $inmediata && ! $permitirHoraPasada && $data['fecha'] === now()->toDateString() && $horaInicio->format('H:i') < now()->format('H:i')) {
            return 'Esa hora ya pasó — elige un horario desde ahora en adelante.';
        }

        $apertura = Carbon::parse(config('salas.apertura'));
        $cierre = Carbon::parse(config('salas.cierre'));

        if ($horaInicio->lessThan($apertura) || $horaFin->greaterThan($cierre)) {
            return 'La reserva debe estar dentro del horario de atención ('.config('salas.apertura').' – '.config('salas.cierre').').';
        }

        $duracion = $horaInicio->diffInMinutes($horaFin);
        $minima = config('salas.duracion_minima');
        $maxima = config('salas.duracion_maxima');

        if ($duracion < $minima || $duracion > $maxima) {
            return "La reserva debe durar entre {$minima} minutos y ".($maxima / 60).' horas.';
        }

        $granularidad = config('salas.granularidad');
        if (! $inmediata && ($horaInicio->minute % $granularidad !== 0 || $horaFin->minute % $granularidad !== 0)) {
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
     * por el cierre y por duracion_maxima — usado para "Reservar ahora" y para avisar
     * en el frontend cuánto tiempo real queda disponible antes de que choque con la
     * siguiente reserva. Devuelve 0 si la sala está ocupada justo en ese instante.
     */
    public function duracionMaximaDisponible(int $salaId, string $fecha, string $desde): int
    {
        $desde = Carbon::parse($desde);
        $cierre = Carbon::parse(config('salas.cierre', '21:00'));
        $maxima = config('salas.duracion_maxima', 120);

        if ($desde->greaterThanOrEqualTo($cierre)) {
            return 0;
        }

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
     * salas() (portal) para no duplicar el armado del JSON en los dos lados (ver
     * convención 4 de CLAUDE.md).
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
                'personas' => $r->participantes->map(fn ($u) => ['rut' => $u->rut, 'nombre' => "{$u->nombre} {$u->apellido}"])->values(),
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
}
