<?php

namespace App\Support;

/**
 * Una materia (650) escrita siempre igual, para que no se dupliquen por el formato:
 * «mapuches--historia», «MAPUCHES  —  HISTORIA» o «MAPUCHES -- HISTORIA.» quedan como
 * «mapuches -- historia» / «MAPUCHES -- HISTORIA». Las mayúsculas se respetan (y si ya
 * existe escrita igual salvo mayúsculas o tildes, LibroController usa esa).
 *
 * No toca el guion simple: en «SISTEMAS HOMBRE - MAQUINA» o «ACTIVO - PASIVO» es parte
 * del nombre, no una subdivisión.
 */
final class Materia
{
    /** Abreviaturas que terminan en punto («PROBLEMAS, EJERCICIOS, ETC.»): el punto se queda. */
    private const ABREVIATURAS = ['etc', 'ed', 'eds', 'ca', 'aprox', 'sr', 'sra', 'dr', 'dra', 's.a', 'ltda', 'inc', 'cía', 'cia'];

    public static function normalizar(?string $nombre): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $nombre));
        // Las subdivisiones: dos o más guiones, o una raya entre espacios, con un espacio a cada lado
        $t = trim(preg_replace(['/\s*-{2,}\s*/u', '/\s+[—–]\s+/u'], ' -- ', $t));
        // Sin «--» colgando al principio o al final
        $t = trim(preg_replace('/^(?:--\s*)+|(?:\s*--)+$/u', '', $t));

        // El punto final, salvo el de una abreviatura o una inicial
        if (str_ends_with($t, '.') && ! str_ends_with($t, '...')) {
            $ultima = mb_strtolower(preg_replace('/^.*[\s(]/u', '', mb_substr($t, 0, -1)));
            if (! in_array($ultima, self::ABREVIATURAS, true) && mb_strlen($ultima) > 1) {
                $t = rtrim(mb_substr($t, 0, -1));
            }
        }

        return $t;
    }
}
