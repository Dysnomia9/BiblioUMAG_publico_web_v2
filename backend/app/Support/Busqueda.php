<?php

namespace App\Support;

/**
 * Búsquedas de texto que no distinguen tildes ni ñ: «calculo» encuentra «Cálculo» y
 * «munoz», «Muñoz». En MySQL lo resuelve la collation de las tablas
 * (utf8mb4_unicode_ci: sin distinguir tildes, ñ ni mayúsculas), así que basta un
 * LIKE; el texto buscado igual se pasa sin tildes. La consulta usa los macros
 * whereSinTildes / orWhereSinTildes (AppServiceProvider).
 */
final class Busqueda
{
    /**
     * La expresión SQL (columna ya escapada o expresión) para comparar sin tildes. En
     * MySQL es la misma expresión: la collation ya compara sin tildes ni mayúsculas.
     */
    public static function sinTildes(string $expresionSql): string
    {
        return $expresionSql;
    }

    /** Nombre y apellido juntos, para buscar «sebastian sanchez» en una sola expresión. */
    public static function nombreCompleto(string $tabla = ''): string
    {
        $prefijo = $tabla ? "{$tabla}." : '';

        return "CONCAT({$prefijo}nombre, ' ', {$prefijo}apellido)";
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

    /** Patrón para LIKE: el texto buscado sin tildes, entre %. */
    public static function patron(string $texto): string
    {
        return '%'.Texto::sinTildes($texto).'%';
    }
}
