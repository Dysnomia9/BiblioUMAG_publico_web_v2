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
     * @return list<string>
     */
    public static function palabras(string $texto): array
    {
        return preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** Patrón para ilike: el texto buscado sin tildes, entre %. */
    public static function patron(string $texto): string
    {
        return '%'.Texto::sinTildes($texto).'%';
    }
}
