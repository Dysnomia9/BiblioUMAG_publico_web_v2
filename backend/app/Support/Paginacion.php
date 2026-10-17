<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Los listados que pueden crecer sin límite (libros, préstamos, catálogo del portal,
 * historial) se piden por páginas: así una consulta nunca trae miles de filas de una
 * vez, ni se queda sin memoria el servidor. `por_pagina` lo elige la pantalla, dentro
 * de un tope.
 */
final class Paginacion
{
    public const TOPE = 500;

    public static function porPagina(Request $request, int $porDefecto = 50, int $tope = self::TOPE): int
    {
        return max(1, min((int) $request->query('por_pagina', $porDefecto), $tope));
    }
}
