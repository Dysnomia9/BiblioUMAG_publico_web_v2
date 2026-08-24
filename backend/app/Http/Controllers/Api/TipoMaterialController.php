<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoMaterial;
use Illuminate\Http\Request;

/** Catálogo de tipos de material (libro/revista/tesis/...) — solo admin puede crearlos (Administración), a diferencia de autores/categorías/carreras que cualquier staff puede crear al vuelo desde catalogación. */
class TipoMaterialController extends Controller
{
    public function index()
    {
        return response()->json(TipoMaterial::orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:tipos_material,nombre'],
        ]);

        $tipoMaterial = TipoMaterial::create($data);

        return response()->json($tipoMaterial, 201);
    }
}
