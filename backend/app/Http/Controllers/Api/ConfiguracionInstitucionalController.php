<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucional;
use Illuminate\Http\Request;

class ConfiguracionInstitucionalController extends Controller
{
    /** Todo staff puede leerla — la necesita cualquiera generando la Constancia de No Multa, no solo admin. */
    public function show()
    {
        return response()->json(ConfiguracionInstitucional::actual());
    }

    public function actualizar(Request $request)
    {
        $data = $request->validate([
            'jefe_unidad_nombre' => ['required', 'string', 'max:255'],
            'jefe_unidad_cargo' => ['required', 'string', 'max:255'],
        ]);

        $configuracion = ConfiguracionInstitucional::actual();
        $configuracion->update($data);

        return response()->json($configuracion);
    }
}
