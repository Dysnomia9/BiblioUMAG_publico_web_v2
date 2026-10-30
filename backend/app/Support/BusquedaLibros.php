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
    public const CAMPOS_PERSONAL = ['titulo', 'titulo_alternativo', 'clasificacion', 'isbn', 'issn', 'autores', 'codigos'];

    public const CAMPOS_PORTAL = ['titulo', 'titulo_alternativo', 'autores', 'categorias', 'isbn_sin_guiones'];

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

        $partes = [];
        foreach ($campos as $campo) {
            $partes[] = match ($campo) {
                'titulo' => $libros()->whereRaw(Busqueda::sinTildes('titulo').' ilike ?', [$sinTildes]),
                'titulo_alternativo' => $libros()->whereRaw(Busqueda::sinTildes('titulo_alternativo').' ilike ?', [$sinTildes]),
                'clasificacion' => $libros()->where('clasificacion', 'ilike', $tal),
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
                'codigos' => DB::table('ejemplares')->select('libro_id as id')
                    ->where(fn ($q) => $q->where('codigo_barras', 'ilike', $tal)->orWhere('codigo_lomo', 'like', $tal)->orWhere('numero_registro', trim($texto))),
            };
        }

        $partes = array_values(array_filter($partes));
        $union = array_shift($partes);
        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }

        return $union;
    }
}
