<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CredencialSimulada;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\LoginUnificadoService;
use App\Support\Credenciales;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso con correo del login v2, para el personal y los usuarios por igual: con el
 * correo y la clave simulados de credenciales_simuladas (config/login_v2.php), o con el
 * correo real de la cuenta y su propia contraseña. Las cuentas siguen entrando como
 * siempre por /login y el portal.
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

        $cuenta = $this->cuentaConClave(mb_strtolower(trim($data['correo'])), $data['password']);

        if (! $cuenta) {
            throw ValidationException::withMessages(['correo' => ['El correo o la contraseña no coinciden.']]);
        }

        if (! $cuenta->activo) {
            throw ValidationException::withMessages(['correo' => ['Tu cuenta está inactiva. Contacta a la biblioteca.']]);
        }

        $sesion = $this->loginUnificado->emitir($cuenta);

        return response()->json(['tipo' => $sesion['tipo'], 'token' => $sesion['token']]);
    }

    /**
     * La cuenta de ese correo si la clave es la suya: el correo simulado con la clave de
     * prueba, o el correo real con la contraseña de la cuenta (la que crea sistema:iniciar
     * no tiene correo simulado). Si el mismo correo es de las dos, vale cualquiera de las
     * claves. Sin ninguna cuenta, la clave igual se compara una vez (Credenciales).
     */
    private function cuentaConClave(string $correo, string $clave): Staff|Usuario|null
    {
        $simulada = CredencialSimulada::with('cuenta')->where('correo', $correo)->first();
        $real = Staff::where('email', $correo)->first() ?? Usuario::where('email', $correo)->first();

        $intentos = array_filter([
            [$simulada?->cuenta, $simulada?->clave],
            [$real, $real?->password],
        ], fn (array $intento) => $intento[0] !== null);

        foreach ($intentos ?: [[null, null]] as [$cuenta, $hash]) {
            if (Credenciales::coinciden($clave, $hash)) {
                return $cuenta;
            }
        }

        return null;
    }
}
