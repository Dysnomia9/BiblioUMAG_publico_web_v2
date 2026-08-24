<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->throttleApi();
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureIsStaff::class,
            'usuario' => \App\Http\Middleware\EnsureIsUsuario::class,
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
        ]);

        // Sin esto, ApplicationBuilder::withMiddleware() deja su propio default
        // (redirectGuestsTo(fn () => route('login'))) activo — y como este proyecto
        // es 100% API (no hay ninguna ruta web llamada 'login', ver routes/web.php),
        // cualquier request SIN "Accept: application/json" explícito (curl liso,
        // healthchecks, etc. — el frontend real vía axios sí lo manda, por eso nunca
        // se notó) a una ruta auth:sanctum protegida tira RouteNotFoundException y
        // devuelve 500 en vez de un 401 limpio. null acá hace que Authenticate
        // lance AuthenticationException sin intentar redirigir a ningún lado, y el
        // handler por defecto de Laravel ya sabe renderizar eso como 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Con solo redirectGuestsTo(null) de arriba no alcanza: el Handler por
        // defecto de Laravel también decide por su cuenta si renderizar JSON o
        // intentar redirect()->guest(route('login')) — vía shouldReturnJson(),
        // que sin esto vuelve a caer en $request->expectsJson() (mismo problema
        // de origen, un nivel más abajo). Como esta app es 100% API, siempre es JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
