<?php

namespace App\Support;

/**
 * La descripción física (300) y la edición (250) como las deja la migración desde Horizon
 * (etl: con_punto_final): espacios ordenados y punto final. «350 p.:il.;24 cm» →
 * «350 p. : il. ; 24 cm.». No corrige abreviaturas («p» sigue siendo «p»): eso lo decide
 * quien cataloga.
 */
final class PuntuacionIsbd
{
    public static function normalizar(?string $texto): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $texto));
        if ($t === '') {
            return null;
        }

        // « : », « ; » y « + » (material acompañante) con un espacio a cada lado. Los dos
        // puntos entre dígitos no se tocan («1:50.000», «12:30»).
        $t = preg_replace(['/[;+]/u', '/(?<!\d):|:(?!\d)/u'], [' $0 ', ' : '], $t);
        $t = trim(preg_replace('/ {2,}/u', ' ', $t), ' ;:+');

        return preg_match('/[.?!]$/u', $t) ? $t : "{$t}.";
    }
}
