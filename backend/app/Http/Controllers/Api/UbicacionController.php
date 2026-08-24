<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ubicacion;
use Illuminate\Http\Request;

/** Catálogo de ubicaciones físicas de los ejemplares — solo admin puede crearlas (Administración), a diferencia de autores/categorías/carreras que cualquier staff puede crear al vuelo desde catalogación. */
class UbicacionController extends Controller
{
    public function index()
    {
        return response()->json(Ubicacion::orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:ubicaciones,nombre'],
        ]);

        $ubicacion = Ubicacion::create($data);

        return response()->json($ubicacion, 201);
    }
}
