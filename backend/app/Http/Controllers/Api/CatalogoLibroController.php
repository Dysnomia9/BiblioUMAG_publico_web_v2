<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Usuario;
use App\Support\OpcionesCatalogo;
use App\Support\Texto;
use Illuminate\Http\Request;

/**
 * Listas de catálogo para los combobox y filtros de libros. Autores y categorías se
 * buscan por lo que se escribe (?q=) y llegan de a pocas (ver OpcionesCatalogo); las
 * carreras son pocas (unos cientos) y van completas.
 */
class CatalogoLibroController extends Controller
{
    public function autores(Request $request)
    {
        return response()->json(OpcionesCatalogo::buscar(Autor::class, $request->query('q')));
    }

    public function categorias(Request $request)
    {
        return response()->json(OpcionesCatalogo::buscar(Categoria::class, $request->query('q')));
    }

    /**
     * Las carreras del catálogo de libros (los filtros buscan por su id). Con ?con_usuarios=1,
     * también las que tienen los usuarios y todavía ningún libro, sin id: son las sugerencias
     * de «Carreras asociadas» al catalogar, y entran al catálogo cuando se asignan a un libro
     * (LibroController::sincronizarPivotes). Una carrera escrita con otras tildes o
     * mayúsculas no se repite; entre las de usuarios gana la que trae minúsculas.
     */
    public function carreras(Request $request)
    {
        $catalogo = Carrera::orderBy('nombre')->get(['id', 'nombre']);
        if (! $request->boolean('con_usuarios')) {
            return response()->json($catalogo);
        }

        $porClave = $catalogo->mapWithKeys(fn (Carrera $c) => [Texto::clave($c->nombre) => ['id' => $c->id, 'nombre' => $c->nombre]]);
        foreach (Usuario::whereNotNull('carrera')->distinct()->pluck('carrera') as $nombre) {
            $nombre = trim($nombre);
            $clave = Texto::clave($nombre);
            $actual = $porClave->get($clave);
            if ($nombre !== '' && ($actual === null || ($actual['id'] === null && ! preg_match('/\p{Ll}/u', $actual['nombre']) && preg_match('/\p{Ll}/u', $nombre)))) {
                $porClave->put($clave, ['id' => null, 'nombre' => $nombre]);
            }
        }

        return response()->json($porClave->sortKeys()->values());
    }
}
