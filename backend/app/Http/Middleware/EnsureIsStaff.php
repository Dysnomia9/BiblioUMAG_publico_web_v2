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
        if (! $request->user() instanceof Staff) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return $next($request);
    }
}
