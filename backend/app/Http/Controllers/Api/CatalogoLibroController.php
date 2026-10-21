<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Support\OpcionesCatalogo;
use Illuminate\Http\Request;

/**
 * Listas de catálogo para los combobox y filtros de libros. Autores y categorías se
 * buscan por lo que se escribe (?q=) y llegan de a pocas (ver OpcionesCatalogo); las
 * carreras son pocas y van completas.
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

    public function carreras()
    {
        return response()->json(Carrera::orderBy('nombre')->get(['id', 'nombre']));
    }
}
