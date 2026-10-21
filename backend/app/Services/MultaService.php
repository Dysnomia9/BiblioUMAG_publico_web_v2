<?php

namespace App\Services;

use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\Staff;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MultaService
{
    /**
     * Días de atraso cobrables (ya descontado el período de gracia), o 0 si no
     * corresponde cobrar: devuelto a tiempo o ítem sin fecha límite (equipos).
     */
    public function diasAtraso(Prestamo $prestamo, Carbon $fechaDevolucionReal): int
    {
        if ($prestamo->tipo_item !== 'libro' || ! $prestamo->fecha_devolucion) {
            return 0;
        }

        if ($fechaDevolucionReal->lessThanOrEqualTo($prestamo->fecha_devolucion)) {
            return 0;
        }

        // Carbon 3 devuelve diffInDays() con fracción de día: se trunca para no
        // cobrar "4,09 días" por 4 días y 2 horas.
        $dias = (int) floor($prestamo->fecha_devolucion->diffInDays($fechaDevolucionReal)) - config('multas.dias_gracia', 0);

        return max(0, $dias);
    }

    /** Monto de la multa por atraso (config/multas.php), o null si no corresponde. */
    public function calcular(Prestamo $prestamo, Carbon $fechaDevolucionReal): ?int
    {
        $dias = $this->diasAtraso($prestamo, $fechaDevolucionReal);

        if ($dias <= 0) {
            return null;
        }

        $monto = $dias * config('multas.monto_dia');
        $tope = config('multas.tope_maximo');

        return $tope ? min($monto, $tope) : $monto;
    }

    public function generarPorAtraso(Prestamo $prestamo, Carbon $fechaDevolucionReal, Staff $staff): ?Multa
    {
        $monto = $this->calcular($prestamo, $fechaDevolucionReal);

        if (! $monto) {
            return null;
        }

        $dias = $this->diasAtraso($prestamo, $fechaDevolucionReal);

        return Multa::create([
            'usuario_id' => $prestamo->usuario_id,
            'prestamo_id' => $prestamo->id,
            'tipo' => 'atraso',
            'monto' => $monto,
            'estado' => 'pendiente',
            'detalle' => "Devolución de \"{$prestamo->libro_titulo}\" con {$dias} ".($dias === 1 ? 'día' : 'días').' de atraso',
            'dias_atraso' => $dias,
            'origen' => 'sistema',
            'registrada_por_staff_id' => $staff->id,
        ]);
    }

    /**
     * Atraso al devolver la llave de una sala: minutos pasados de la hora de término y
     * monto que tendría la multa (config/multas.php, «sala»). Null si se devolvió dentro
     * de la tolerancia, si estas multas están apagadas, si la reserva no tiene a nadie a
     * cargo o si ya tiene su multa. No multa a nadie: el mesón decide en cada caso con
     * generarPorAtrasoDeSala(), porque los atrasos chicos a veces se dejan pasar.
     *
     * @return array{reserva_id: int, persona: string, sala: string, hora_fin: string, minutos: int, monto: int, monto_provisional: bool}|null
     */
    public function atrasoDeSala(Reserva $reserva): ?array
    {
        if (! config('multas.sala.activa') || ! $reserva->hora_devolucion_real || ! $reserva->usuario_id) {
            return null;
        }

        $termino = Carbon::parse($reserva->fecha->toDateString().' '.$reserva->hora_fin);
        $minutos = (int) floor($termino->diffInMinutes($reserva->hora_devolucion_real));
        $monto = $minutos * (int) config('multas.sala.monto_por_minuto');
        $tope = config('multas.sala.tope_maximo');
        $monto = $tope ? min($monto, (int) $tope) : $monto;

        $yaMultada = Multa::where('reserva_id', $reserva->id)->where('tipo', 'atraso')->where('estado', '!=', 'anulada')->exists();
        if ($minutos <= (int) config('multas.sala.minutos_tolerancia') || $monto < 1 || $yaMultada) {
            return null;
        }

        $reserva->loadMissing(['sala:id,nombre', 'usuario:id,nombre,apellido']);

        return [
            'reserva_id' => $reserva->id,
            'persona' => trim("{$reserva->usuario->nombre} {$reserva->usuario->apellido}"),
            'sala' => $reserva->sala?->nombre ?? 'Sala',
            'hora_fin' => substr($reserva->hora_fin, 0, 5),
            'minutos' => $minutos,
            'monto' => $monto,
            'monto_provisional' => (bool) config('multas.sala.monto_provisional'),
        ];
    }

    /**
     * La multa por devolver tarde la llave, cuando el mesón decide cursarla. Es de quien
     * está a cargo de la reserva. Null si no corresponde (ver atrasoDeSala()). La reserva
     * queda bloqueada mientras tanto: dos clics seguidos no generan dos multas.
     */
    public function generarPorAtrasoDeSala(Reserva $reserva, Staff $staff): ?Multa
    {
        return DB::transaction(function () use ($reserva, $staff) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $atraso = $this->atrasoDeSala($reserva);

            if (! $atraso) {
                return null;
            }

            return Multa::create([
                'usuario_id' => $reserva->usuario_id,
                'reserva_id' => $reserva->id,
                'tipo' => 'atraso',
                'monto' => $atraso['monto'],
                'estado' => 'pendiente',
                'detalle' => "Devolución de la llave de {$atraso['sala']} con {$atraso['minutos']} minutos de atraso (reserva hasta las {$atraso['hora_fin']})",
                'origen' => 'sistema',
                'registrada_por_staff_id' => $staff->id,
            ]);
        });
    }

    /** @param  array{tipo: string, monto: int, detalle: string, prestamo_id?: int|null}  $datos */
    public function crearManual(Usuario $usuario, array $datos, Staff $staff): Multa
    {
        return Multa::create([
            'usuario_id' => $usuario->id,
            'prestamo_id' => $datos['prestamo_id'] ?? null,
            'tipo' => $datos['tipo'],
            'monto' => $datos['monto'],
            'estado' => 'pendiente',
            'detalle' => $datos['detalle'],
            'origen' => 'manual',
            'registrada_por_staff_id' => $staff->id,
        ]);
    }

    public function registrarPago(Multa $multa, Staff $staff): Multa
    {
        $multa->update([
            'estado' => 'pagada',
            'pagada_en' => now(),
            'pagada_por_staff_id' => $staff->id,
        ]);

        return $multa;
    }

    public function anular(Multa $multa, string $motivo, Staff $staff): Multa
    {
        $multa->update([
            'estado' => 'anulada',
            'anulada_en' => now(),
            'anulada_por_staff_id' => $staff->id,
            'motivo_anulacion' => $motivo,
        ]);

        return $multa;
    }

    /**
     * Las multas pendientes bloquean préstamos (libros y equipos) y reservas de
     * libros hasta que se paguen o se anulen. Devuelve el motivo del rechazo, o
     * null si la persona no tiene deuda. $paraElUsuario redacta el mensaje para el
     * propio usuario (portal) en vez de para quien atiende en el mesón.
     */
    public function mensajeBloqueo(Usuario|int $usuario, bool $paraElUsuario = false): ?string
    {
        ['cantidad' => $cantidad, 'monto_total' => $monto] = $this->resumenPendiente($usuario);

        if ($cantidad === 0) {
            return null;
        }

        $deuda = ($cantidad === 1 ? '1 multa pendiente' : "{$cantidad} multas pendientes").' por $'.number_format($monto, 0, ',', '.');
        $pagar = $cantidad === 1 ? 'pagarla' : 'pagarlas';

        return $paraElUsuario
            ? "Tienes {$deuda}: debes {$pagar} en la biblioteca antes de reservar libros."
            : "Este usuario tiene {$deuda}: debe {$pagar} antes de llevarse o reservar material.";
    }

    /** @return array{cantidad: int, monto_total: int} */
    public function resumenPendiente(Usuario|int $usuario): array
    {
        $fila = Multa::pendientes()
            ->where('usuario_id', $usuario instanceof Usuario ? $usuario->id : $usuario)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(monto), 0) as monto_total')
            ->first();

        return [
            'cantidad' => (int) $fila->cantidad,
            'monto_total' => (int) $fila->monto_total,
        ];
    }

    /** Deuda pendiente agrupada por usuario, de mayor a menor monto. */
    public function pendientesPorUsuario(): Collection
    {
        return DB::table('multas')
            ->join('usuarios', 'usuarios.id', '=', 'multas.usuario_id')
            ->where('multas.estado', 'pendiente')
            ->selectRaw('usuarios.id as usuario_id, usuarios.nombre, usuarios.apellido, usuarios.rut, usuarios.email, COUNT(*) as cantidad_multas, SUM(multas.monto) as monto_total, MIN(multas.created_at) as desde')
            ->groupBy('usuarios.id', 'usuarios.nombre', 'usuarios.apellido', 'usuarios.rut', 'usuarios.email')
            ->orderByDesc('monto_total')
            ->get()
            ->map(fn ($fila) => [
                'usuario_id' => (int) $fila->usuario_id,
                'nombre' => $fila->nombre,
                'apellido' => $fila->apellido,
                'rut' => $fila->rut,
                'email' => $fila->email,
                'cantidad_multas' => (int) $fila->cantidad_multas,
                'monto_total' => (int) $fila->monto_total,
                'desde' => $fila->desde,
            ]);
    }
}
