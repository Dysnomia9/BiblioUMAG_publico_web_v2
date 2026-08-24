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
            'usuariosActivos' => Usuario::where('activo', true)->count(),
            'entradasHoy' => Entrada::whereDate('fecha_hora_entrada', $hoy)->count(),
            // Horizon (el sistema legado) no distingue "quién sigue adentro": cada ingreso
            // se cierra en el mismo instante en que se registra (ver EntradaController::
            // store()). "Personas en sala" es, en la práctica, el total de ingresos del día.
            'personasEnSala' => Entrada::whereDate('fecha_hora_entrada', $hoy)->count(),
            'prestamosActivos' => Prestamo::where('estado', 'activo')->count(),
            'prestamosAtrasados' => Prestamo::where('estado', 'activo')
                ->where('fecha_devolucion', '<', now())
                ->count(),
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
