<?php

namespace App\Services;

use App\Models\Ejemplar;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use App\Notifications\ReservaListaParaRetirarNotification;
use App\Support\HorarioBiblioteca;
use App\Support\Notificar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regla de negocio compartida entre ReservaLibroController (staff) y
 * PortalReservaLibroController (autoservicio del portal virtual). Opera
 * sobre `Ejemplar` (copia física, identificada por código de barras) — la
 * reserva en sí (`ReservaLibro`) guarda tanto `libro_id` (la obra deseada,
 * siempre presente) como `ejemplar_id` (la copia física asignada, null
 * mientras está 'en_cola': todavía no se le asignó copia).
 */
class ReservaLibroService
{
    /**
     * @return array{0: ReservaLibro|null, 1: string|null, 2: int}
     */
    public function reservarOEncolar(string $codigoBarras, int $usuarioId, ?string $fechaReserva = null, ?string $fechaRetiro = null, ?int $registradoPorStaffId = null): array
    {
        return DB::transaction(function () use ($codigoBarras, $usuarioId, $fechaReserva, $fechaRetiro, $registradoPorStaffId) {
            $ejemplar = Ejemplar::porCodigo($codigoBarras)->lockForUpdate()->first();

            if (! $ejemplar) {
                return [null, 'Código de barras no encontrado en el sistema', 404];
            }

            return $this->procesarReserva($ejemplar, $usuarioId, $fechaReserva, $fechaRetiro, $registradoPorStaffId);
        });
    }

    /**
     * Igual que reservarOEncolar(), pero para el portal virtual: el usuario navega el
     * catálogo por título (obra), no escanea un código de barras de una copia
     * concreta — no le importa cuál copia física le toque, cualquiera de las de ese
     * libro sirve. Se elige una con preferencia por una que esté disponible ahora
     * mismo; si ninguna lo está, se toma cualquiera en estante para unirse a la cola
     * (la cola ya está compartida entre todas las copias de un mismo libro_id — ver
     * liberarLibro()).
     *
     * @return array{0: ReservaLibro|null, 1: string|null, 2: int}
     */
    public function reservarOEncolarPorLibro(int $libroId, int $usuarioId, ?string $fechaReserva = null, ?string $fechaRetiro = null): array
    {
        return DB::transaction(function () use ($libroId, $usuarioId, $fechaReserva, $fechaRetiro) {
            $ejemplar = Ejemplar::where('libro_id', $libroId)
                ->where('estado_proceso', 'en_estante')
                ->orderByDesc('disponible')
                ->lockForUpdate()
                ->first();

            if (! $ejemplar) {
                return [null, 'Este libro no tiene copias disponibles para préstamo', 404];
            }

            return $this->procesarReserva($ejemplar, $usuarioId, $fechaReserva, $fechaRetiro);
        });
    }

    /** @return array{0: ReservaLibro|null, 1: string|null, 2: int} */
    private function procesarReserva(Ejemplar $ejemplar, int $usuarioId, ?string $fechaReserva, ?string $fechaRetiro, ?int $registradoPorStaffId = null): array
    {
        if ($ejemplar->estado_proceso !== 'en_estante') {
            return [null, "Este libro no está disponible para préstamo (estado: {$ejemplar->estado_proceso})", 409];
        }

        $yaActiva = ReservaLibro::where('libro_id', $ejemplar->libro_id)
            ->where('usuario_id', $usuarioId)
            ->whereIn('estado', ['pendiente', 'en_cola'])
            ->exists();

        if ($yaActiva) {
            return [null, 'Ya tienes una reserva o un lugar en la cola de espera activo para este libro', 409];
        }

        if ($limite = $this->mensajeLimite($usuarioId, $registradoPorStaffId !== null)) {
            return [null, $limite, 409];
        }

        if ($ejemplar->disponible) {
            $reserva = ReservaLibro::create([
                'usuario_id' => $usuarioId,
                'libro_id' => $ejemplar->libro_id,
                'ejemplar_id' => $ejemplar->id,
                'fecha_reserva' => $fechaReserva ?? now()->toDateString(),
                'fecha_retiro' => $fechaRetiro ?? $this->fechaLimiteDeRetiro(),
                'estado' => 'pendiente',
                'registrado_por_staff_id' => $registradoPorStaffId,
            ]);

            $ejemplar->update(['disponible' => false]);

            return [$reserva->load('libro', 'ejemplar'), null, 201];
        }

        // Copia ocupada: se une a la cola de espera (FIFO por id) en vez de
        // rechazar la reserva. ejemplar_id/fecha_retiro quedan null hasta que se
        // le asigne una copia (ver liberarLibro()).
        $reserva = ReservaLibro::create([
            'usuario_id' => $usuarioId,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => null,
            'fecha_reserva' => $fechaReserva ?? now()->toDateString(),
            'fecha_retiro' => null,
            'estado' => 'en_cola',
            'registrado_por_staff_id' => $registradoPorStaffId,
        ]);

        return [$reserva->load('libro'), null, 201];
    }

    /**
     * Enriquece las reservas 'en_cola' de la colección con `posicion` (lugar en la
     * fila, 1 = siguiente) y `proxima_fecha_devolucion` (la fecha de devolución más
     * próxima entre los préstamos activos de copias de ese libro — una estimación de
     * cuándo podría liberarse una copia, no una promesa exacta: no considera cuántas
     * personas hay delante en la fila ni cuántas copias existen, solo la fecha
     * acordada más cercana). Compartido entre ReservaLibroController (staff) y
     * PortalReservaLibroController (portal) para no duplicar esta lógica.
     *
     * @param  Collection<int, ReservaLibro>  $reservas
     */
    public function enriquecerColaLibro(Collection $reservas): void
    {
        foreach ($reservas as $reserva) {
            if ($reserva->estado !== 'en_cola') {
                continue;
            }

            $posicion = ReservaLibro::where('libro_id', $reserva->libro_id)
                ->where('estado', 'en_cola')
                ->where('id', '<', $reserva->id)
                ->count() + 1;
            $reserva->setAttribute('posicion', $posicion);

            $proximaDevolucion = Prestamo::whereHas('ejemplar', fn ($q) => $q->where('libro_id', $reserva->libro_id))
                ->where('estado', '!=', 'devuelto')
                ->whereNotNull('fecha_devolucion')
                ->min('fecha_devolucion');
            $reserva->setAttribute('proxima_fecha_devolucion', $proximaDevolucion);
        }
    }

    /**
     * Cancela la reserva si sigue activa (por retirar o en la lista de espera) y devuelve
     * true; si mientras tanto la retiraron o ya estaba cancelada, no la toca y devuelve
     * false. Como al prestar, se bloquea primero la copia y después la reserva, y el
     * estado se vuelve a leer ya bloqueado: un doble clic, o el portal y el mesón a la
     * vez, no liberan dos veces la misma copia ni dejan disponible una ya prestada.
     * $reserva queda con su estado actual.
     */
    public function cancelar(ReservaLibro $reserva): bool
    {
        $cancelada = DB::transaction(function () use ($reserva) {
            [$actual, $ejemplar] = $this->bloquearSiSigueActiva($reserva);

            if (! $actual) {
                return false;
            }

            $liberaEjemplar = $actual->estado === 'pendiente';
            $actual->update(['estado' => 'cancelado']);

            // Si estaba 'en_cola' (nunca llegó a tener copia asignada), cancelar no
            // afecta la disponibilidad de nadie más — simplemente sale de la fila.
            if ($liberaEjemplar && $ejemplar) {
                $this->liberarLibro($ejemplar);
            }

            return true;
        });

        $reserva->refresh();

        return $cancelada;
    }

    /**
     * Al prestarle a alguien una copia de un título que tenía reservado por otra copia, o
     * cuya lista de espera esperaba, ya se lo lleva: esa reserva queda retirada con la
     * copia prestada, y la que tenía apartada pasa al siguiente de la fila. Sin esto, la
     * copia apartada quedaba retenida hasta la fecha límite y la persona no podía reservar
     * otro libro. Va dentro de la transacción del préstamo, con $prestada ya bloqueada.
     * Devuelve cuántas reservas cerró.
     */
    public function cerrarAlPrestarOtraCopia(int $usuarioId, Ejemplar $prestada): int
    {
        $abiertas = ReservaLibro::where('usuario_id', $usuarioId)
            ->where('libro_id', $prestada->libro_id)
            ->whereIn('estado', ['pendiente', 'en_cola'])
            ->get();

        $cerradas = 0;
        foreach ($abiertas as $reserva) {
            [$actual, $apartada] = $this->bloquearSiSigueActiva($reserva);

            if (! $actual) {
                continue;
            }

            $actual->update(['estado' => 'retirado', 'ejemplar_id' => $prestada->id]);
            if ($apartada && $apartada->id !== $prestada->id) {
                $this->liberarLibro($apartada);
            }
            $cerradas++;
        }

        return $cerradas;
    }

    /**
     * Como al prestar, primero la copia apartada y después la reserva, para no trabarse
     * con un préstamo o una cancelación simultáneos; el estado se lee ya bloqueado.
     * [null, null] si la reserva ya no está activa (la retiraron o la cancelaron).
     *
     * @return array{0: ReservaLibro|null, 1: Ejemplar|null}
     */
    private function bloquearSiSigueActiva(ReservaLibro $reserva): array
    {
        $ejemplar = $reserva->ejemplar_id ? Ejemplar::whereKey($reserva->ejemplar_id)->lockForUpdate()->first() : null;
        $actual = ReservaLibro::whereKey($reserva->id)->lockForUpdate()->first();

        if (! in_array($actual?->estado, ['pendiente', 'en_cola'], true)) {
            return [null, null];
        }

        // Estaba en la fila y justo le tocó una copia: esa es la que se libera.
        if ($actual->ejemplar_id && $actual->ejemplar_id !== $ejemplar?->id) {
            $ejemplar = Ejemplar::whereKey($actual->ejemplar_id)->lockForUpdate()->first();
        }

        return [$actual, $ejemplar];
    }

    /**
     * Las reservas listas para retirar cuyo plazo ya pasó (la fecha límite es el último
     * día para retirarla): se cancelan y su copia pasa al siguiente de la lista de
     * espera, o vuelve a quedar disponible. Sin esto, la copia quedaba apartada para
     * siempre y la fila no avanzaba. Lo corre cada noche reservas-libro:vencer.
     *
     * @return Collection<int, ReservaLibro> las que vencieron
     */
    public function vencerSinRetirar(): Collection
    {
        $vencidas = collect();

        $candidatas = ReservaLibro::where('estado', 'pendiente')
            ->whereDate('fecha_retiro', '<', now()->toDateString())
            ->orderBy('id')
            ->get(['id', 'ejemplar_id']);

        foreach ($candidatas as $candidata) {
            $vencida = DB::transaction(function () use ($candidata) {
                // Como al prestar: primero la copia y después la reserva, así no se
                // traba con un retiro que llega justo en ese momento.
                $ejemplar = $candidata->ejemplar_id ? Ejemplar::whereKey($candidata->ejemplar_id)->lockForUpdate()->first() : null;
                $reserva = ReservaLibro::whereKey($candidata->id)->lockForUpdate()->first();

                // La retiraron o la cancelaron mientras tanto.
                if ($reserva?->estado !== 'pendiente') {
                    return null;
                }

                $reserva->update(['estado' => 'cancelado']);
                if ($ejemplar) {
                    $this->liberarLibro($ejemplar);
                }

                return $reserva;
            });

            if ($vencida) {
                $vencidas->push($vencida);
            }
        }

        return $vencidas;
    }

    /**
     * Se llama siempre que una copia pasa a estar disponible: al devolver un
     * préstamo (PrestamoController::devolver()), al cancelar una reserva
     * 'pendiente' o al volver la copia al estante (EjemplarController). En vez de
     * dejarla simplemente disponible, promueve a la primera persona en la cola de
     * espera de ese título (FIFO por id) si hay alguien — la copia sigue "no
     * disponible", ahora apartada para esa persona, con una fecha_retiro nueva desde
     * este momento. Devuelve esa reserva, o null si la copia quedó disponible.
     */
    public function liberarLibro(Ejemplar $ejemplar): ?ReservaLibro
    {
        // orderBy('id') en vez de created_at: dos filas creadas en el mismo segundo
        // tendrían el mismo timestamp y empatarían el orden FIFO; el id autoincremental
        // no tiene esa ambigüedad.
        $siguiente = ReservaLibro::where('libro_id', $ejemplar->libro_id)
            ->where('estado', 'en_cola')
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if (! $siguiente) {
            $ejemplar->update(['disponible' => true]);

            return null;
        }

        $siguiente->update([
            'estado' => 'pendiente',
            'ejemplar_id' => $ejemplar->id,
            'fecha_retiro' => $this->fechaLimiteDeRetiro(),
        ]);
        // Una copia que vuelve al estante llega disponible: desde ahora queda apartada.
        $ejemplar->update(['disponible' => false]);

        // Sin SMTP configurado (ver .env.example), esto solo queda escrito en
        // storage/logs/laravel.log — no falla ni intenta salir a la red.
        $siguiente->load(['usuario', 'libro']);
        Notificar::enviar($siguiente->usuario, new ReservaListaParaRetirarNotification($siguiente));

        return $siguiente;
    }

    /**
     * Null si la persona todavía puede tener otra reserva; si no, el mensaje de por
     * qué no. Cuentan juntas las por retirar y las listas de espera (máximo de
     * config/reservas_libro.php, 1 de fábrica).
     */
    private function mensajeLimite(int $usuarioId, bool $paraStaff): ?string
    {
        // Bloquea a la persona hasta el final de la transacción: dos reservas
        // simultáneas de distintos libros no pueden contar las dos bajo el máximo.
        Usuario::whereKey($usuarioId)->lockForUpdate()->first();

        $maximo = (int) config('reservas_libro.maximo_por_persona', 1);
        $activas = ReservaLibro::where('usuario_id', $usuarioId)->whereIn('estado', ['pendiente', 'en_cola'])->count();

        if ($activas < $maximo) {
            return null;
        }

        $cuantas = $maximo === 1 ? 'una reserva de libro activa' : "{$maximo} reservas de libros activas";

        return $paraStaff
            ? "La persona ya tiene {$cuantas} (por retirar o en lista de espera), el máximo a la vez: debe retirarla o cancelarla antes de reservar otro libro."
            : "Ya tienes {$cuantas} (por retirar o en lista de espera), el máximo a la vez: retírala o cancélala en «Mis reservas» antes de reservar otro libro.";
    }

    /** Los días de config/reservas_libro.php desde hoy; si el plazo cae en un día sin atención, se corre al siguiente. */
    public function fechaLimiteDeRetiro(): string
    {
        return HorarioBiblioteca::proximoDiaConAtencion(now()->addDays(config('reservas_libro.dias_para_retirar')))->toDateString();
    }
}
