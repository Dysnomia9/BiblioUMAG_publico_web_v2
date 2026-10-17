<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoMaterial;
use App\Support\Bitacora;
use App\Support\FichaBibliografica;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de tipos de material (libro, revista, trabajo de titulación...). Se
 * administra solo desde Administración, a diferencia de autores/categorías/carreras
 * que se crean al vuelo al catalogar. Cada tipo elige la plantilla de su ficha
 * bibliográfica: qué etiquetas MARC lleva y cuáles ve el usuario.
 */
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
            'plantilla' => ['sometimes', Rule::in(array_keys(FichaBibliografica::PLANTILLAS))],
        ]);

        $tipoMaterial = TipoMaterial::create($data);

        Bitacora::registrar('configuracion.crear_tipo_material', "Creó el tipo de material «{$tipoMaterial->nombre}»", entidad: $tipoMaterial);

        return response()->json($tipoMaterial->fresh(), 201);
    }

    public function update(Request $request, TipoMaterial $tipoMaterial)
    {
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255', Rule::unique('tipos_material', 'nombre')->ignore($tipoMaterial->id)],
            'plantilla' => ['sometimes', Rule::in(array_keys(FichaBibliografica::PLANTILLAS))],
        ]);

        $antes = $tipoMaterial->only(array_keys($data));
        $tipoMaterial->update($data);

        Bitacora::registrar(
            'configuracion.editar_tipo_material',
            "Editó el tipo de material «{$tipoMaterial->nombre}»",
            entidad: $tipoMaterial,
            datos: Bitacora::cambios($antes, $data),
        );

        return response()->json($tipoMaterial);
    }
}
