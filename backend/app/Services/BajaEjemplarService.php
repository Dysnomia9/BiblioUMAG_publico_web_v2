<?php

namespace App\Services;

use App\Models\Ejemplar;
use App\Models\EjemplarEstadoHistorial;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Support\Bitacora;

/**
 * Sacar una copia de circulación. A veces la copia está prestada y no vuelve (se
 * perdió): con la confirmación explícita del personal se da de baja igual y el
 * préstamo queda cerrado, sin multa automática: si corresponde cobrar la pérdida, se
 * cursa a mano desde Multas. Quien llama bloquea la copia (lockForUpdate) antes.
 */
class BajaEjemplarService
{
    /** Por qué no se puede dar de baja ahora, o null. Una copia apartada para alguien sí lo impide. */
    public function bloqueo(Ejemplar $ejemplar): ?string
    {
        $apartada = ReservaLibro::where('ejemplar_id', $ejemplar->id)->where('estado', 'pendiente')->exists();

        return $apartada
            ? 'Esta copia está apartada para una reserva lista para retirar: cancela esa reserva antes de darla de baja.'
            : null;
    }

    /** El préstamo en curso de la copia, si está prestada. */
    public function prestamoActivo(Ejemplar $ejemplar): ?Prestamo
    {
        return Prestamo::with('usuario:id,nombre,apellido,rut,tipo_documento,pais')
            ->where('ejemplar_id', $ejemplar->id)
            ->where('estado', 'activo')
            ->lockForUpdate()
            ->first();
    }

    /** Da de baja la copia y cierra su préstamo si lo tenía. Devuelve ese préstamo, si lo había. */
    public function darDeBaja(Ejemplar $ejemplar, string $motivo, Staff $staff, ?string $loteId = null): ?Prestamo
    {
        $prestamo = $this->prestamoActivo($ejemplar);
        $estadoAnterior = $ejemplar->estado_proceso;
        $estadoPersonalizadoAnteriorId = $ejemplar->estado_personalizado_id;

        if ($prestamo) {
            $prestamo->update([
                'estado' => 'devuelto',
                'fecha_devolucion_real' => now(),
                'devuelto_por' => $staff->nombre,
                'devuelto_por_staff_id' => $staff->id,
            ]);

            Bitacora::registrar(
                'prestamos.cerrar_por_baja',
                "Cerró el préstamo de «{$prestamo->libro_titulo}» porque la copia se dio de baja. Motivo: {$motivo}",
                entidad: $prestamo,
                datos: ['codigo_barras' => $prestamo->codigo_barras, 'motivo' => $motivo],
                afectado: $prestamo->usuario,
            );
        }

        $ejemplar->update([
            'estado_proceso' => 'de_baja',
            'estado_personalizado_id' => null,
            // Una copia perdida no está disponible aunque ya no tenga préstamo.
            'disponible' => $prestamo ? false : $ejemplar->disponible,
        ]);

        EjemplarEstadoHistorial::create([
            'ejemplar_id' => $ejemplar->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => 'de_baja',
            'estado_personalizado_anterior_id' => $estadoPersonalizadoAnteriorId,
            'estado_personalizado_nuevo_id' => null,
            'staff_id' => $staff->id,
            'lote_id' => $loteId,
            'motivo' => $motivo,
        ]);

        return $prestamo;
    }

    /** Al sacarla de baja, una copia que quedó "no disponible" por pérdida vuelve a estarlo si nadie la tiene. */
    public function reincorporar(Ejemplar $ejemplar): void
    {
        if ($ejemplar->disponible) {
            return;
        }

        $ocupada = Prestamo::where('ejemplar_id', $ejemplar->id)->where('estado', 'activo')->exists()
            || ReservaLibro::where('ejemplar_id', $ejemplar->id)->where('estado', 'pendiente')->exists();

        if (! $ocupada) {
            $ejemplar->update(['disponible' => true]);
        }
    }
}
