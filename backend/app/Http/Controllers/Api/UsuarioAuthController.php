<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La sesión del portal. Se entra por el login único (IngresoController), con el correo.
 */
class UsuarioAuthController extends Controller
{
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * El fondo del portal en modo día (menú de la cuenta › Aspecto visual). Los mismos fondos
     * que el personal; como allá, no toca updated_at ni queda en la bitácora.
     */
    public function cambiarFondo(Request $request)
    {
        $data = $request->validate([
            'fondo' => ['required', 'string', Rule::in(Staff::FONDOS)],
        ], [
            'fondo.required' => 'Elige un fondo.',
            'fondo.in' => 'Ese fondo no existe.',
        ]);

        $usuario = $request->user();
        $usuario->timestamps = false;
        $usuario->forceFill(['fondo' => $data['fondo']])->save();

        return response()->json(['fondo' => $usuario->fondo]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
