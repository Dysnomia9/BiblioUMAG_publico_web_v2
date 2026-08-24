<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;

/** Listas de catálogo (autores/categorías/carreras) para poblar los combobox de catalogación de libros. */
class CatalogoLibroController extends Controller
{
    public function autores()
    {
        return response()->json(Autor::orderBy('nombre')->get(['id', 'nombre']));
    }

    public function categorias()
    {
        return response()->json(Categoria::orderBy('nombre')->get(['id', 'nombre']));
    }

    public function carreras()
    {
        return response()->json(Carrera::orderBy('nombre')->get(['id', 'nombre']));
    }
}
