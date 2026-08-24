<?php

namespace App\Services;

use App\Models\Prestamo;
use Carbon\Carbon;

class MultaService
{
    /**
     * Calcula la multa por atraso al momento de la devolución, o null si no corresponde
     * (a tiempo, tipo de ítem sin fecha límite —audífonos/notebook—, o dentro del período
     * de gracia). El monto/gracia/tope se configuran en config/multas.php.
     */
    public function calcular(Prestamo $prestamo, Carbon $fechaDevolucionReal): ?int
    {
        if ($prestamo->tipo_item !== 'libro' || ! $prestamo->fecha_devolucion) {
            return null;
        }

        if ($fechaDevolucionReal->lessThanOrEqualTo($prestamo->fecha_devolucion)) {
            return null;
        }


        $diasAtraso = (int) floor($prestamo->fecha_devolucion->diffInDays($fechaDevolucionReal)) - config('multas.dias_gracia', 0);

        if ($diasAtraso <= 0) {
            return null;
        }

        $monto = $diasAtraso * config('multas.monto_dia');
        $tope = config('multas.tope_maximo');

        return $tope ? min($monto, $tope) : $monto;
    }
}
