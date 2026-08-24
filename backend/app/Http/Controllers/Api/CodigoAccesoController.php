<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodigoAcceso;
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

        return response()->json($codigo, 201);
    }
}
