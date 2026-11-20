<?php

namespace App\Support;

/**
 * Los enlaces del catálogo (856) que se muestran como vínculo: solo direcciones web,
 * http o https con un servidor. FILTER_VALIDATE_URL y la regla «url» de Laravel aceptan
 * también otros esquemas: «javascript://%0aalert(1)» pasa por el primero y «data://…»
 * por los dos, y en un href ejecutarían código en la sesión de quien hace clic.
 */
final class Enlace
{
    public static function esWeb(?string $url): bool
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $partes = parse_url($url);

        return in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true) && filled($partes['host'] ?? null);
    }

    /** La dirección si es web; si no, null (se muestra como texto, sin vínculo). */
    public static function web(?string $url): ?string
    {
        $url = $url === null ? null : trim($url);

        return self::esWeb($url) ? $url : null;
    }

    /** Regla de validación para un campo que guarda una dirección web. */
    public static function regla(): \Closure
    {
        return function (string $campo, mixed $valor, \Closure $falla) {
            if (filled($valor) && ! self::esWeb(trim((string) $valor))) {
                $falla('El enlace debe ser una dirección web que empiece con https:// o http://.');
            }
        };
    }
}
