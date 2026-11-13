<?php

namespace App\Support;

use App\Models\Ejemplar;

/**
 * Tomo y copia del tomo de un ejemplar, como los numera Horizon: «v.1 c.2» es el tomo 1,
 * copia 2 de ese tomo; «t.4» es el tomo 4 (una sola copia). En BiblioUMAG van en
 * ejemplares.tomo y ejemplares.copia_tomo; el texto completo sigue en volumen.
 *
 * Horizon también junta tomo y volumen, en el orden en que se escribió: «t.1-v.II»,
 * «t.2-v.I-c.1», «v.1-t.2» (unas 90 copias). Ese par es el «tomo» de la copia («t.1-v.II»):
 * las copias se numeran dentro de él, igual que dentro de un tomo solo.
 */
final class TomoEjemplar
{
    /** Tomo o volumen y su número, también en romanos («v.II»); tras el número del primero, una letra («t.2a») que no es la «c» de la copia pegada («t.1c.1»). */
    private const PATRON = '/^(t|tomo|v|vol|volumen)\.?\s*(\d+(?:[a-z](?![.\s]*\d))?|[ivxlc]+(?![a-z]))'
        // el segundo, del otro tipo: «t.1-v.II», «t.1 v.2», «v.2- t.98», «t.4-vol.II»
        .'(?:\s*[-,;\/]?\s*(t|tomo|v|vol|volumen)\.?\s*(\d+|[ivxlc]+)(?![a-z]))?'
        // la copia: «c.2», «-c.1», «/c.1», «c2»
        .'\s*(?:[-,;\/]\s*|\s)?(?:c\.?\s*(\d+))?\.?$/iu';

    /**
     * «v.1 c.2», «v.1-c.3», «t.4», «Tomo 2», «vol. 3 c.1», «t.2-v.I-c.1» → ['v.1', 2],
     * ['v.1', 3], ['t.4', null]… ['t.2-v.I', 1]. Lo que no es un tomo («No. 367 (Ene., 2014)»)
     * → [null, null]. Igual que tomo_de_volumen() del ETL.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public static function desdeEtiqueta(?string $etiqueta): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $etiqueta));
        if (! preg_match(self::PATRON, $texto, $m)) {
            return [null, null];
        }
        $segundo = $m[3] ?? '';
        // «t.1 t.2» no es tomo y volumen
        if ($segundo !== '' && mb_strtolower($segundo[0]) === mb_strtolower($m[1][0])) {
            return [null, null];
        }
        $tomo = self::parte($m[1], $m[2]).($segundo !== '' ? '-'.self::parte($segundo, $m[4]) : '');

        return [$tomo, isset($m[5]) && $m[5] !== '' ? (int) $m[5] : null];
    }

    /** «Tomo», «1» → «t.1»; «vol», «ii» → «v.II» (los romanos en mayúscula, como en Horizon). */
    private static function parte(string $tipo, string $numero): string
    {
        $numero = ctype_digit($numero) || preg_match('/^\d/', $numero) ? mb_strtolower($numero) : mb_strtoupper($numero);

        return mb_strtolower(mb_substr($tipo, 0, 1)).'.'.$numero;
    }

    /** «Tomo 1», «T1», «t. 1» → «t.1»; «Vol 2», «v2» → «v.2»; «t.1 v.II» → «t.1-v.II»; otro texto queda como se escribió. */
    public static function normalizar(?string $tomo): ?string
    {
        $tomo = trim(preg_replace('/\s+/u', ' ', (string) $tomo));
        if ($tomo === '') {
            return null;
        }
        [$clave, $copia] = self::desdeEtiqueta($tomo);

        return $clave !== null && $copia === null ? $clave : $tomo;
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
