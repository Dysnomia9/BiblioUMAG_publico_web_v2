<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\Bitacora;
use App\Support\Credenciales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Los correos se guardan en minúscula (Staff::email()): da igual cómo se escriba.
        $staff = Staff::where('email', Str::lower(trim($data['email'])))->first();

        // La clave se compara aunque la cuenta no exista: que no se note por el tiempo.
        $claveCorrecta = Credenciales::coinciden($data['password'], $staff?->password);

        if (! $staff || ! $claveCorrecta) {
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

        Bitacora::registrar('sesion.ingresar', 'Inició sesión en el panel del personal', entidad: $staff, actor: $staff);

        return response()->json([
            'token' => $token,
            'staff' => $staff->perfil(),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->perfil());
    }

    public function cambiarPassword(Request $request)
    {
        $data = $request->validate([
            'actual' => ['required', 'string'],
            'nueva' => ['required', 'string', Password::min(8), 'confirmed', 'different:actual'],
        ], [
            'nueva.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'nueva.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'nueva.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        $staff = $request->user();

        if (! Hash::check($data['actual'], $staff->password)) {
            throw ValidationException::withMessages(['actual' => ['La contraseña actual no es correcta.']]);
        }

        $staff->update(['password' => $data['nueva']]);

        // Cierra las demás sesiones abiertas con la contraseña anterior, pero no la actual.
        $idTokenActual = $staff->currentAccessToken()?->getKey();
        $staff->tokens()->when($idTokenActual, fn ($q) => $q->whereKeyNot($idTokenActual))->delete();

        Bitacora::registrar('sesion.cambiar_password', 'Cambió su propia contraseña', entidad: $staff);

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
