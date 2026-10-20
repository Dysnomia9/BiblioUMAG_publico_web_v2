<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CredencialSimulada;
use App\Services\LoginUnificadoService;
use App\Support\Credenciales;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso con correo del login v2, para el personal y los usuarios por igual, con los
 * correos y claves simulados de credenciales_simuladas (config/login_v2.php). Las
 * cuentas siguen entrando como siempre por /login y el portal.
 */
class LoginV2Controller extends Controller
{
    public function __construct(private LoginUnificadoService $loginUnificado)
    {
    }

    public function login(Request $request)
    {
        if (! config('login_v2.correos_simulados') || app()->isProduction()) {
            return response()->json(['message' => 'El ingreso con correo del login v2 no está habilitado en este servidor.'], 404);
        }

        $data = $request->validate(
            ['correo' => ['required', 'email', 'max:255'], 'password' => ['required', 'string']],
            ['correo.required' => 'Ingresa tu correo institucional.', 'correo.email' => 'Ingresa tu correo institucional (por ejemplo, igcontre@umag.cl).'],
        );

        $credencial = CredencialSimulada::with('cuenta')->where('correo', mb_strtolower(trim($data['correo'])))->first();

        $claveCorrecta = Credenciales::coinciden($data['password'], $credencial?->clave);

        if (! $credencial?->cuenta || ! $claveCorrecta) {
            throw ValidationException::withMessages(['correo' => ['El correo o la contraseña no coinciden.']]);
        }

        if (! $credencial->cuenta->activo) {
            throw ValidationException::withMessages(['correo' => ['Tu cuenta está inactiva. Contacta a la biblioteca.']]);
        }

        $sesion = $this->loginUnificado->emitir($credencial->cuenta);

        return response()->json(['tipo' => $sesion['tipo'], 'token' => $sesion['token']]);
    }
}
