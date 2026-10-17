<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Numeración correlativa sin huecos repetidos, en lugar de las secuencias de
 * PostgreSQL (MySQL no tiene): cada contador es una fila de la tabla `contadores`, y
 * siguiente() la bloquea (SELECT ... FOR UPDATE) mientras toma el número, así dos
 * copias catalogadas al mismo tiempo nunca reciben el mismo.
 */
final class Contador
{
    public static function siguiente(string $nombre, int $inicio = 1): int
    {
        return DB::transaction(function () use ($nombre, $inicio) {
            $actual = DB::table('contadores')->where('nombre', $nombre)->lockForUpdate()->value('valor');

            if ($actual === null) {
                DB::table('contadores')->insert(['nombre' => $nombre, 'valor' => $inicio]);

                return $inicio;
            }

            DB::table('contadores')->where('nombre', $nombre)->update(['valor' => $actual + 1]);

            return $actual + 1;
        });
    }
}
