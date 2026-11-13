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

    /**
     * 490: los paréntesis los pone la ficha al mostrarla, «(Serie Harry Potter ; 1)», como
     * el punto final del 300. Se guarda sin ellos, una serie por línea: si quien cataloga
     * los escribe igual, o vienen así de Horizon («(HARRY POTTER)», «(La Ley / Thomson
     * Reuters»), se quitan los de los bordes para que no queden dobles.
     */
    public static function serie(?string $texto): ?string
    {
        $series = collect(preg_split('/\R/u', (string) $texto))
            ->map(fn (string $s) => self::sinParentesis(trim(preg_replace('/\s+/u', ' ', $s))))
            ->filter(fn (string $s) => $s !== '');

        return $series->isEmpty() ? null : $series->implode("\n");
    }

    private static function sinParentesis(string $serie): string
    {
        if (preg_match('/^\((.*)\)$/u', $serie, $m)) {
            return trim($m[1]);
        }
        // Solo el de un lado, si no cierra uno de adentro: «(La Ley» o «Ley)», pero no «Serie (2a. época)»
        if (str_starts_with($serie, '(') && ! str_contains($serie, ')')) {
            return trim(substr($serie, 1));
        }
        if (str_ends_with($serie, ')') && ! str_contains($serie, '(')) {
            return trim(substr($serie, 0, -1));
        }

        return $serie;
    }
}
