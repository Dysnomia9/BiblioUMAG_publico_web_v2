<?php

namespace App\Providers;

use App\Support\Busqueda;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Búsquedas que no distinguen tildes ni ñ: «calculo» encuentra «Cálculo».
        Builder::macro('whereSinTildes', function (string $columna, string $texto, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->whereRaw(Busqueda::sinTildes($this->getGrammar()->wrap($columna)).' ilike ?', [Busqueda::patron($texto)], $boolean);
        });
        Builder::macro('orWhereSinTildes', function (string $columna, string $texto) {
            /** @var Builder $this */
            return $this->whereSinTildes($columna, $texto, 'or');
        });

        // Cada palabra buscada en cualquier parte del texto y en cualquier orden, sin tildes:
        // «ignacio contreras norambuena» encuentra a «Ignacio Alexis Andrés Contreras
        // Norambuena», aunque se salten los segundos nombres. Recibe una expresión SQL.
        Builder::macro('whereCadaPalabra', function (string $expresionSql, string $texto, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->where(function (Builder $q) use ($expresionSql, $texto) {
                foreach (Busqueda::palabras($texto) as $palabra) {
                    $q->whereRaw(Busqueda::sinTildes($expresionSql).' ilike ?', [Busqueda::patron($palabra)]);
                }
            }, boolean: $boolean);
        });
        Builder::macro('orWhereCadaPalabra', function (string $expresionSql, string $texto) {
            /** @var Builder $this */
            return $this->whereCadaPalabra($expresionSql, $texto, 'or');
        });

        // Límite general de la API. Con sesión iniciada se cuenta por cuenta, y el
        // personal y los usuarios por separado (el staff #3 y el usuario #3 son
        // personas distintas). El margen es para el mesón en hora punta: cada
        // entrada registrada son dos solicitudes. Sin sesión (solo los logins), por IP.
        RateLimiter::for('api', function (Request $request) {
            $cuenta = $request->user();

            return $cuenta
                ? Limit::perMinute(240)->by(class_basename($cuenta).':'.$cuenta->getKey())
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        // Intentos de login: por cuenta + IP, como hace Laravel Breeze. Contarlos
        // solo por IP bloquearía a todo el campus a la vez, porque sus computadores
        // y celulares suelen salir a internet por la misma IP. El identificador se
        // normaliza para que escribir el RUT con o sin puntos cuente como el mismo.
        RateLimiter::for('login', function (Request $request) {
            $identificador = (string) ($request->input('email') ?? $request->input('rut') ?? $request->input('usuario') ?? '');
            $cuenta = Str::lower(preg_replace('/[^0-9a-zA-Z@]/', '', $identificador));

            return Limit::perMinute(6)->by('login:'.$cuenta.'|'.$request->ip());
        });
    }
}
