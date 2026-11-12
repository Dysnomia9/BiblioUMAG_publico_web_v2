<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrada;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function resumen()
    {
        $hoy = Carbon::today();

        return response()->json([
            // Las que sirven hoy: una cuenta vencida guarda activo = true y su fecha ya pasada.
            'usuariosActivos' => Usuario::vigentes()->count(),
            // Solo ingresos: no se marca la salida (como en Horizon), así que no se sabe
            // cuántos siguen adentro ni tiene sentido compararlo con un aforo.
            'entradasHoy' => Entrada::whereDate('fecha_hora_entrada', $hoy)->count(),
            'prestamosActivos' => Prestamo::where('estado', '!=', 'devuelto')->count(),
            // El que vence hoy todavía no está atrasado, igual que en el Listado de préstamos.
            'prestamosAtrasados' => Prestamo::vencidos()->count(),
            'ultimasEntradas' => Entrada::with('usuario:id,nombre,apellido,rut')
                ->latest('fecha_hora_entrada')
                ->limit(5)
                ->get(),
            'ultimosPrestamos' => Prestamo::with('usuario:id,nombre,apellido,rut')
                ->latest('fecha_prestamo')
                ->limit(5)
                ->get(),
        ]);
    }
}
