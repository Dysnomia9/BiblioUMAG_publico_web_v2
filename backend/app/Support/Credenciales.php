<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Contraseñas de los logins sin revelar qué cuentas existen. Si la cuenta no existe (o
 * no tiene contraseña), la clave igual se compara contra un hash de relleno: así la
 * respuesta tarda lo mismo y no se puede averiguar por el tiempo qué correos o RUT están
 * registrados (comparar con bcrypt tarda unos 0,2 s; sin esto, «no existe» respondía
 * al tiro).
 */
final class Credenciales
{
    public static function coinciden(string $clave, ?string $hash): bool
    {
        $iguales = Hash::check($clave, $hash ?: self::relleno());

        return $hash !== null && $hash !== '' && $iguales;
    }

    /**
     * Un hash con el mismo algoritmo y costo que los reales. Se guarda en la caché: PHP no
     * conserva nada entre una solicitud y otra, y calcularlo en cada intento haría que
     * «no existe» tardara el doble (uno para crearlo y otro para compararlo).
     */
    private static function relleno(): string
    {
        $hash = Cache::get('login.hash_relleno');

        if (! is_string($hash) || Hash::needsRehash($hash)) {
            $hash = Hash::make(Str::random(40));
            Cache::forever('login.hash_relleno', $hash);
        }

        return $hash;
    }
}
