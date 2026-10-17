<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodigoAcceso;
use App\Support\Bitacora;
use Illuminate\Http\Request;

class CodigoAccesoController extends Controller
{
    public function show()
    {
        return response()->json(CodigoAcceso::vigente());
    }

    public function regenerar(Request $request)
    {
        $codigo = CodigoAcceso::generar($request->user()->id);

        Bitacora::registrar('configuracion.regenerar_qr', 'Regeneró el código QR de acceso (el anterior dejó de funcionar)', entidad: $codigo);

        return response()->json($codigo, 201);
    }
}
