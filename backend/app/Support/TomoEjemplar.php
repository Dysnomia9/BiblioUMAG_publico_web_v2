<?php

namespace App\Support;

use App\Models\Ejemplar;

/**
 * Tomo y copia del tomo de un ejemplar, como los numera Horizon: «v.1 c.2» es el tomo 1,
 * copia 2 de ese tomo; «t.4» es el tomo 4 (una sola copia). En BiblioUMAG van en
 * ejemplares.tomo y ejemplares.copia_tomo; el texto completo sigue en volumen.
 */
final class TomoEjemplar
{
    /**
     * «v.1 c.2», «v.1-c.3», «t.4», «Tomo 2», «vol. 3 c.1» → ['v.1', 2], ['v.1', 3], ['t.4', null]…
     * Lo que no es un tomo («No. 367 (Ene., 2014)») → [null, null].
     *
     * @return array{0: string|null, 1: int|null}
     */
    public static function desdeEtiqueta(?string $etiqueta): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $etiqueta));
        // La letra tras el número del tomo («t.2a») no es la «c» de la copia pegada («t.1c.1»);
        // también «t.2/c.1». Igual que tomo_de_volumen() del ETL.
        if (! preg_match('/^(t|tomo|v|vol|volumen)\.?\s*(\d+(?:[a-z](?![.\s]*\d))?)\s*(?:[-,;\/]\s*|\s)?(?:c\.?\s*(\d+))?\.?$/iu', $texto, $m)) {
            return [null, null];
        }

        return [self::normalizar($m[1].$m[2]), isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
    }

    /** «Tomo 1», «T1», «t. 1» → «t.1»; «Vol 2», «v2» → «v.2»; otro texto queda como se escribió. */
    public static function normalizar(?string $tomo): ?string
    {
        $tomo = trim(preg_replace('/\s+/u', ' ', (string) $tomo));
        if ($tomo === '') {
            return null;
        }
        if (preg_match('/^(t|tomo|v|vol|volumen)\.?\s*(\d+[a-z]?)$/iu', $tomo, $m)) {
            return mb_strtolower(mb_substr($m[1], 0, 1)).'.'.mb_strtolower($m[2]);
        }

        return $tomo;
    }

    /**
     * La «c.N» de la copia que se agrega a un tomo (o, con $tomo null, a un título sin tomos),
     * como numera Horizon: la primera no lleva «c.» (null: «t.4» sola, o nada), la segunda es
     * la c.1, la tercera la c.2… Con «t.1» y «t.1 c.1», la 2; con solo «t.2 c.1», también la 2.
     */
    public static function siguienteCopia(int $libroId, ?string $tomo): ?int
    {
        $copias = Ejemplar::where('libro_id', $libroId)
            ->when($tomo === null, fn ($q) => $q->whereNull('tomo'), fn ($q) => $q->where('tomo', $tomo))
            ->get(['id', 'numero_copia', 'tomo', 'copia_tomo', 'fecha_ingreso_horizon']);
        if ($copias->isEmpty()) {
            return null;
        }

        // La «c.N» que ya lleva cada una (su etiqueta_copia); sin «c.», la 0
        return $copias->map(fn (Ejemplar $e) => (int) preg_replace('/^c\./', '', (string) $e->etiqueta_copia))->max() + 1;
    }

    /** El texto de volumen que se muestra en la copia: «t.1 c.3», o «t.1» si es la primera. */
    public static function etiqueta(string $tomo, ?int $copia): string
    {
        return $copia === null ? $tomo : "{$tomo} c.{$copia}";
    }
}
