<?php

namespace App\Support;

/**
 * Comparar y presentar textos escritos de distintas formas: «INGENIERÍA CIVIL» e
 * «Ingeniería civil» son la misma carrera, y un nombre que llega en mayúsculas desde
 * un reporte de la universidad se guarda como nombre propio.
 */
final class Texto
{
    public const CON_TILDE = 'ÁÀÄÂÉÈËÊÍÌÏÎÓÒÖÔÚÙÜÛÑÇáàäâéèëêíìïîóòöôúùüûñç';

    public const SIN_TILDE = 'AAAAEEEEIIIIOOOOUUUUNCaaaaeeeeiiiioooouuuunc';

    /** Palabras que van en minúscula dentro de un nombre o una carrera, salvo al comienzo. */
    private const MINUSCULAS = ['a', 'al', 'con', 'da', 'das', 'de', 'del', 'do', 'dos', 'e', 'el', 'en', 'la', 'las', 'los', 'o', 'para', 'por', 'u', 'van', 'von', 'y'];

    public static function sinTildes(string $texto): string
    {
        return strtr($texto, array_combine(mb_str_split(self::CON_TILDE), mb_str_split(self::SIN_TILDE)));
    }

    /** Para comparar: sin tildes, en minúscula y con un solo espacio entre palabras. */
    public static function clave(?string $texto): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', self::sinTildes((string) $texto))));
    }

    /**
     * «INGENIERÍA CIVIL EN MECÁNICA» → «Ingeniería Civil en Mecánica»; «O'HIGGINS» →
     * «O'Higgins». Solo cambia lo que viene entero en mayúsculas (así llegan los
     * reportes de matrícula): un texto que ya trae minúsculas se respeta tal cual.
     */
    public static function nombrePropio(?string $texto): ?string
    {
        if ($texto === null || preg_match('/\p{Ll}/u', $texto) || ! preg_match('/\p{Lu}/u', $texto)) {
            return $texto;
        }

        $partes = preg_split('/(\s+)/u', mb_strtolower($texto), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $primera = true;

        foreach ($partes as $i => $parte) {
            if (trim($parte) === '') {
                continue;
            }
            if (! $primera && in_array($parte, self::MINUSCULAS, true)) {
                $primera = false;

                continue;
            }
            $partes[$i] = preg_replace_callback('/\p{L}+/u', fn (array $m) => mb_convert_case($m[0], MB_CASE_TITLE), $parte);
            $primera = false;
        }

        return implode('', $partes);
    }
}
