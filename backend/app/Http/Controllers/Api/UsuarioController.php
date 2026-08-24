<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $query = Usuario::query();

        if ($busqueda = $request->query('q')) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('nombre', 'ilike', "%{$busqueda}%")
                    ->orWhere('apellido', 'ilike', "%{$busqueda}%")
                    ->orWhere('rut', 'ilike', "%{$busqueda}%")
                    ->orWhere('carrera', 'ilike', "%{$busqueda}%");
            });
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(
            $query->orderBy('nombre')->orderBy('apellido')->get()
        );
    }

    public function porRut(string $rut)
    {
        $usuario = Usuario::where('rut', $rut)->first();

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $multas = $usuario->prestamos()
            ->where('multa_estado', 'pendiente')
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(multa_monto), 0) as monto_total')
            ->first();

        $usuario->setAttribute('multas_pendientes', [
            'cantidad' => (int) $multas->cantidad,
            'monto_total' => (int) $multas->monto_total,
        ]);

        return response()->json($usuario);
    }
}
