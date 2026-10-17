<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucional;
use App\Support\Bitacora;
use App\Support\HorarioBiblioteca;
use Illuminate\Http\Request;

class ConfiguracionInstitucionalController extends Controller
{
    /** Todo staff puede leerla — la necesita cualquiera generando la Constancia de No Multa, no solo admin. */
    public function show()
    {
        return response()->json(ConfiguracionInstitucional::actual());
    }

    /** Horario de atención (config/biblioteca.php): para avisar antes de elegir un día sin atención. */
    public function horario()
    {
        return response()->json(HorarioBiblioteca::semana());
    }

    public function actualizar(Request $request)
    {
        $data = $request->validate([
            'jefe_unidad_nombre' => ['required', 'string', 'max:255'],
            'jefe_unidad_cargo' => ['required', 'string', 'max:255'],
        ]);

        $configuracion = ConfiguracionInstitucional::actual();
        $antes = $configuracion->only(array_keys($data));
        $configuracion->update($data);

        Bitacora::registrar(
            'configuracion.institucional',
            'Cambió quién firma la Constancia de No Multa',
            entidad: $configuracion,
            datos: Bitacora::cambios($antes, $data),
        );

        return response()->json($configuracion);
    }
}
