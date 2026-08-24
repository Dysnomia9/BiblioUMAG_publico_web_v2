<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::where('rut', $data['rut'])->first();

        if (! $usuario || ! $usuario->password || ! Hash::check($data['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'rut' => ['Las credenciales no coinciden con ningún registro.'],
            ]);
        }

        if (! $usuario->activo) {
            throw ValidationException::withMessages([
                'rut' => ['Tu cuenta se encuentra inactiva. Contacta a biblioteca.'],
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
