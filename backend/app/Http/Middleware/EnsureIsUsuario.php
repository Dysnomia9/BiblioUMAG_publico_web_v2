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
        if (! $request->user() instanceof Usuario) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return $next($request);
    }
}
