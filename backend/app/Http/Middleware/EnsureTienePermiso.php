<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Support\Permisos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('permiso:libros.catalogar'). Con varias claves
 * ('permiso:prestamos.gestionar,listados.ver') basta con tener cualquiera.
 */
class EnsureTienePermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $staff = $request->user();

        if ($staff instanceof Staff) {
            foreach ($permisos as $permiso) {
                if ($staff->puede($permiso)) {
                    return $next($request);
                }
            }
        }

        $etiquetas = implode('» o «', array_map(Permisos::etiqueta(...), $permisos));

        return response()->json([
            'message' => "Tu rol no tiene el permiso «{$etiquetas}». Si lo necesitas, pídeselo a un administrador.",
        ], 403);
    }
}
