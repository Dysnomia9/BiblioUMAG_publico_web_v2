<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda de libros por texto, hecha para catálogos grandes: en vez de revisar cada
 * título preguntando «¿está en el título, o en algún autor, o en algún código...?»,
 * busca la palabra en cada campo por separado —cada uno con su índice de trigramas
 * (migración 2026_10_03_000003)— y une los ids encontrados. La consulta principal
 * queda con un simple `libros.id IN (...)`.
 */
final class BusquedaLibros
{
    // isbn_sin_guiones también en el personal: el lector lee el código de barras del libro sin
    // guiones («9789564006062») y el ISBN pudo catalogarse con ellos («978-956-400-606-2»).
    public const CAMPOS_PERSONAL = ['titulo', 'titulo_alternativo', 'mencion_responsabilidad', 'clasificacion', 'isbn', 'isbn_sin_guiones', 'issn', 'autores', 'profesor_guia', 'codigos', 'numero_ficha'];

    public const CAMPOS_PORTAL = ['titulo', 'titulo_alternativo', 'mencion_responsabilidad', 'autores', 'categorias', 'isbn_sin_guiones'];

    /**
     * Deja en la consulta solo los libros en que aparece cada palabra del texto (en cualquier
     * campo y orden). Los ids de cada palabra se cruzan con INTERSECT: así cada una se busca
     * una sola vez. Con un «whereIn» por palabra, Postgres a veces repetía la búsqueda de una
     * palabra común por cada resultado de las otras («Jose canuman chacon»: «jose» está en
     * ~2.800 menciones, y se buscaba 61 veces; 2,4 s en vez de 0,2 s).
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $consulta
     * @param  list<string>  $campos
     */
    public static function filtrar($consulta, string $columna, string $texto, array $campos): void
    {
        $partes = array_map(fn (string $palabra) => self::ids($palabra, $campos), Busqueda::palabras($texto));
        if (count($partes) <= 1) {
            if ($partes) {
                $consulta->whereIn($columna, $partes[0]);
            }

            return;
        }

        $sql = implode(' INTERSECT ', array_map(fn (Builder $parte) => '('.$parte->toSql().')', $partes));
        $consulta->whereRaw("{$columna} in ({$sql})", array_merge(...array_map(fn (Builder $parte) => $parte->getBindings(), $partes)));
    }

    /**
     * El N.º de ficha escrito en el texto, o null. Se entiende también copiado de la pantalla
     * («Ficha N.º 62961», «Ficha Nº 62961», «N° 62961», «#62961», «62.961»). Con $soloElNumero
     * (se está buscando solo por ficha) basta el número; si no, tiene que decir «ficha», «N.º»
     * o «#»: un número suelto puede ser un código, un año o un ISBN.
     */
    public static function numeroDeFicha(string $texto, bool $soloElNumero = false): ?int
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        if (! preg_match('/^(?:(ficha)\s*:?\s*)?(?:(n\s*[.ºo°]+|n[uú]mero|#)\s*:?\s*)?(\d{1,3}(?:\.\d{3})+|\d+)$/iu', $texto, $m)) {
            return null;
        }
        $conEtiqueta = $m[1] !== '' || $m[2] !== '';
        $numero = str_replace('.', '', $m[3]);
        if ((! $conEtiqueta && ! $soloElNumero) || strlen($numero) > 12) {
            return null;
        }

        return (int) $numero;
    }

    /**
     * Ids de los libros en que aparece el texto en alguno de los campos pedidos.
     *
     * @param  list<string>  $campos
     */
    public static function ids(string $texto, array $campos): Builder
    {
        $sinTildes = Busqueda::patron($texto);
        $tal = "%{$texto}%";
        $libros = fn () => DB::table('libros')->select('id');

        // Los códigos de barras, de lomo, registros, ISBN e ISSN siempre llevan dígitos: con
        // «talcahuano» no se recorren las ~115.000 copias ni esas columnas (issn no tiene
        // índice). La clasificación se busca siempre: ~1.250 revistas se ubican por un nombre
        // sin dígitos («Memoria anualFulbright», «Acanthus»), y tiene su índice de trigramas.
        if (! Busqueda::tieneDigito($texto)) {
            $campos = array_diff($campos, ['codigos', 'isbn', 'issn', 'isbn_sin_guiones', 'numero_ficha']);
        }

        $partes = [];
        foreach ($campos as $campo) {
            $partes[] = match ($campo) {
                'titulo' => $libros()->whereRaw(Busqueda::sinTildes('titulo').' ilike ?', [$sinTildes]),
                'titulo_alternativo' => $libros()->whereRaw(Busqueda::sinTildes('titulo_alternativo').' ilike ?', [$sinTildes]),
                // 245 $c: hay personas que solo figuran ahí, sin un 700 («Shara L. Schwartzberg»)
                'mencion_responsabilidad' => $libros()->whereRaw(Busqueda::sinTildes('mencion_responsabilidad').' ilike ?', [$sinTildes]),
                'clasificacion' => $libros()->where('clasificacion', 'ilike', $tal),
                // El profesor guía de las tesis (texto en el libro, no un autor del catálogo).
                'profesor_guia' => $libros()->whereRaw(Busqueda::sinTildes('profesor_guia').' ilike ?', [$sinTildes]),
                // El propio o cualquiera de los otros (el de la colección encuentra todos sus títulos).
                'isbn' => $libros()->where(fn ($q) => $q->where('isbn', 'ilike', $tal)->orWhere('otros_isbn', 'ilike', $tal)),
                'issn' => $libros()->where('issn', 'ilike', $tal),
                // El ISBN sin guiones: «9789567189501» encuentra «978-956-7189-50-1».
                'isbn_sin_guiones' => strlen($isbn = preg_replace('/[^0-9Xx]/', '', $texto)) >= 4
                    ? $libros()->where(fn ($q) => $q->whereRaw("replace(isbn, '-', '') ilike ?", ["%{$isbn}%"])
                        ->orWhereRaw("replace(otros_isbn, '-', '') ilike ?", ["%{$isbn}%"]))
                    : null,
                'autores' => DB::table('libro_autor')->join('autores', 'autores.id', '=', 'libro_autor.autor_id')
                    ->select('libro_autor.libro_id as id')
                    ->whereRaw(Busqueda::sinTildes('autores.nombre').' ilike ?', [$sinTildes]),
                'categorias' => DB::table('libro_categoria')->join('categorias', 'categorias.id', '=', 'libro_categoria.categoria_id')
                    ->select('libro_categoria.libro_id as id')
                    ->whereRaw(Busqueda::sinTildes('categorias.nombre').' ilike ?', [$sinTildes]),
                // El código de 14 dígitos o el de lomo de cualquiera de sus copias, o su número
                // de registro de Horizon (exacto: «307» no trae todos los que lo contienen).
                // El N.º de ficha, exacto: «307» es la ficha 307, no todas las que lo contienen.
                'numero_ficha' => ($numero = self::numeroDeFicha($texto, soloElNumero: true)) !== null
                    ? $libros()->where('numero_ficha', $numero)
                    : null,
                'codigos' => DB::table('ejemplares')->select('libro_id as id')
                    ->where(fn ($q) => $q->where('codigo_barras', 'ilike', $tal)->orWhere('codigo_lomo', 'like', $tal)->orWhere('numero_registro', trim($texto))),
            };
        }

        $partes = array_values(array_filter($partes));
        // Sin ningún campo que buscar (p. ej. solo campos de códigos y una palabra sin dígitos)
        $union = array_shift($partes) ?? $libros()->whereRaw('false');
        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }

        return $union;
    }
}
