<?php

namespace App\Support;

/**
 * Búsquedas de texto que no distinguen tildes ni ñ: «calculo» encuentra «Cálculo» y
 * «munoz», «Muñoz». Se compara la columna sin tildes (translate de Postgres, sin
 * extensiones) contra el texto buscado, también sin tildes. La consulta usa los
 * macros whereSinTildes / orWhereSinTildes (AppServiceProvider).
 */
final class Busqueda
{
    /** La expresión SQL (columna ya escapada o expresión) sin tildes. */
    public static function sinTildes(string $expresionSql): string
    {
        return "translate({$expresionSql}, '".Texto::CON_TILDE."', '".Texto::SIN_TILDE."')";
    }

    /**
     * Las palabras del texto buscado, para exigir que cada una aparezca en algún campo
     * y en cualquier orden: «calculo stewart» encuentra «Cálculo Diferencial e
     * Integral - Stewart», aunque las palabras no vayan seguidas ni en el mismo campo.
     *
     * Las palabras vacías («el», «de», «para»...) y las de 1 o 2 letras se descartan si
     * queda alguna otra: casi todos los títulos las contienen, así que no filtran nada, y
     * son las más caras, porque los índices de trigramas no sirven con menos de 3 letras
     * y Postgres termina recorriendo todo el catálogo. «el puerto de talcahuano» se
     * busca como «puerto talcahuano». Se conservan las cortas con dígitos o símbolos
     * («2», «v1», «c#»), y si todas son cortas («de», «ph») se busca tal cual.
     *
     * @return list<string>
     */
    public static function palabras(string $texto): array
    {
        $palabras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
        $utiles = array_values(array_filter($palabras, fn (string $p) => ! self::esVacia($p)));

        return $utiles ?: $palabras;
    }

    private const VACIAS = ['el', 'la', 'lo', 'los', 'las', 'de', 'del', 'y', 'e', 'o', 'u', 'en', 'a', 'al',
        'un', 'una', 'unos', 'unas', 'por', 'para', 'con', 'sin', 'que', 'se', 'su', 'sus'];

    /** Palabra que no filtra nada: un artículo, preposición o conjunción, o solo 1 o 2 letras. */
    private static function esVacia(string $palabra): bool
    {
        return in_array(mb_strtolower($palabra), self::VACIAS, true)
            || (mb_strlen($palabra) <= 2 && preg_match('/^\p{L}+$/u', $palabra) === 1);
    }

    /** Si la palabra tiene algún dígito: solo así puede ser parte de un código, ISBN o ISSN. */
    public static function tieneDigito(string $palabra): bool
    {
        return preg_match('/\d/', $palabra) === 1;
    }

    /** Patrón para ilike: el texto buscado sin tildes, entre %. */
    public static function patron(string $texto): string
    {
        return '%'.Texto::sinTildes($texto).'%';
    }
}
