<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Support\Credenciales;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UsuarioAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // RUT, pasaporte o DNI (estudiantes extranjeros), con o sin puntos y guion.
        $usuario = Usuario::buscarPorIdentificador($data['rut']);

        // La clave se compara aunque la cuenta no exista o no tenga contraseña (cargada por
        // planilla): que no se note por el tiempo qué RUT están registrados.
        $claveCorrecta = Credenciales::coinciden($data['password'], $usuario?->password);

        if (! $usuario || ! $claveCorrecta) {
            throw ValidationException::withMessages([
                'rut' => ['Las credenciales no coinciden con ningún registro.'],
            ]);
        }

        if (! $usuario->activo) {
            throw ValidationException::withMessages([
                'rut' => [$usuario->mensajeCuentaInactiva()],
            ]);
        }

        $token = $usuario->createToken('usuario-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => $usuario,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
