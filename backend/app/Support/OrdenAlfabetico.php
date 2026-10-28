<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ordenar texto como se espera en español: «Álgebra» junto a la A, la ñ después de la n y
 * sin separar mayúsculas de minúsculas. El Postgres de la imagen (Alpine) ordena byte a
 * byte aunque la base diga en_US.utf8 (Á y las minúsculas quedaban después de la Z), así
 * que se usa la intercalación ICU en español; si el servidor no la trae, al menos se ordena
 * sin distinguir mayúsculas.
 */
final class OrdenAlfabetico
{
    /** La expresión SQL con que se ordena esa columna. */
    public static function de(string $columna): string
    {
        return self::hayIcu() ? "{$columna} collate \"es-x-icu\"" : "lower({$columna})";
    }

    private static function hayIcu(): bool
    {
        return Cache::rememberForever('bd.orden_es_icu', fn () => (bool) DB::selectOne(
            "select exists (select 1 from pg_collation where collname = 'es-x-icu') as hay",
        )->hay);
    }
}
