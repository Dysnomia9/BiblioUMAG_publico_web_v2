<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Staff) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 401 (no 403) para que el frontend cierre la sesión: una cuenta desactivada
        // no debe seguir operando con un token emitido antes de desactivarla.
        if (! $user->activo) {
            return response()->json(['message' => 'Tu cuenta fue desactivada. Contacta a un administrador.'], 401);
        }

        return $next($request);
    }
}
