<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $staff = Staff::where('email', $data['email'])->first();

        if (! $staff || ! Hash::check($data['password'], $staff->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con ningún registro.'],
            ]);
        }

        if (! $staff->activo) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta se encuentra inactiva. Contacta a un administrador.'],
            ]);
        }

        $token = $staff->createToken('staff-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'staff' => $staff,
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
