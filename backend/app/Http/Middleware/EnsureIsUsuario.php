<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsUsuario
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 401 (no 403) para que el portal cierre la sesión: una cuenta desactivada no
        // debe seguir usando un token emitido antes de desactivarla (duran hasta 8 horas).
        if (! $usuario->activo) {
            return response()->json(['message' => $usuario->mensajeCuentaInactiva()], 401);
        }

        return $next($request);
    }
}
