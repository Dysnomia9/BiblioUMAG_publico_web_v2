<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
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

    /**
     * Títulos de la A a la Z; después los que empiezan con un número y al final los que
     * empiezan con un signo («¿Qué es…?», «[Mapa]», «"Cien años…"»), que si no quedaban primero.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function porTitulo(Builder $query, string $columna = 'titulo'): Builder
    {
        return $query
            ->orderByRaw("case when {$columna} ~ '^[[:alpha:]]' then 0 when {$columna} ~ '^[[:digit:]]' then 1 else 2 end")
            ->orderByRaw(self::de($columna));
    }

    private static function hayIcu(): bool
    {
        return Cache::rememberForever('bd.orden_es_icu', fn () => (bool) DB::selectOne(
            "select exists (select 1 from pg_collation where collname = 'es-x-icu') as hay",
        )->hay);
    }
}
