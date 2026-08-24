<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EstadoLibroPersonalizado;
use Illuminate\Http\Request;

class EstadoLibroPersonalizadoController extends Controller
{
    /** Todo staff puede listarlos (los necesita el selector de EstadoLibroView) — solo admin puede crear/desactivar. */
    public function index(Request $request)
    {
        $query = EstadoLibroPersonalizado::query();

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:estados_libro_personalizados,nombre'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $estado = EstadoLibroPersonalizado::create($data + ['activo' => true]);

        return response()->json($estado, 201);
    }

    public function cambiarActivo(Request $request, EstadoLibroPersonalizado $estadoLibroPersonalizado)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $estadoLibroPersonalizado->update(['activo' => $data['activo']]);

        return response()->json($estadoLibroPersonalizado);
    }
}
