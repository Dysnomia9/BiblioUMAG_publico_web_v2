<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CredencialSimulada;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\LoginUnificadoService;
use App\Support\Credenciales;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El login único, para el personal y los usuarios por igual: correo y contraseña. El RUT (o
 * el pasaporte o DNI) no sirve para entrar. El servidor busca de quién es el correo y
 * responde a qué sesión corresponde; si la misma persona tiene cuenta del personal y de
 * usuario con esa clave, le pregunta con cuál entra (LoginUnificadoService::resolver()).
 *
 * En desarrollo también valen los correos y la clave simulados de credenciales_simuladas
 * (config/login_v2.php); nunca en producción.
 */
class IngresoController extends Controller
{
    public function __construct(private LoginUnificadoService $loginUnificado)
    {
    }

    public function ingresar(Request $request)
    {
        $data = $request->validate([
            'identificador' => ['required', 'string', 'max:255', 'email'],
            'password' => ['required', 'string'],
            'como' => ['nullable', Rule::in(['staff', 'usuario'])],
        ], [
            'identificador.required' => 'Ingresa tu correo institucional.',
            'identificador.email' => 'Se entra con el correo institucional (por ejemplo, nombre@umag.cl), no con el RUT.',
            'password.required' => 'Ingresa tu contraseña.',
        ]);

        $cuentas = $this->cuentasConClave(Str::lower(trim($data['identificador'])), $data['password']);

        if (! $cuentas) {
            throw ValidationException::withMessages(['identificador' => ['El correo y la contraseña no coinciden.']]);
        }

        $activas = array_values(array_filter($cuentas, fn ($cuenta) => $cuenta->activo));
        if (! $activas) {
            $cuenta = $cuentas[0];
            throw ValidationException::withMessages(['identificador' => [
                $cuenta instanceof Usuario ? $cuenta->mensajeCuentaInactiva() : 'Tu cuenta se encuentra inactiva. Contacta a un administrador.',
            ]]);
        }

        $resultado = $this->loginUnificado->resolver($activas, $data['como'] ?? null, 'correo');
        if (! $resultado) {
            throw ValidationException::withMessages(['identificador' => ['Esa cuenta no está disponible con estos datos.']]);
        }

        return response()->json(isset($resultado['token'])
            ? ['tipo' => $resultado['tipo'], 'token' => $resultado['token']]
            : $resultado);
    }

    /**
     * Las cuentas de ese correo cuya contraseña es la que se escribió: la del personal, la
     * del usuario (por su correo principal o uno adicional) y, en desarrollo, la del correo
     * simulado. Sin ninguna, la clave igual se compara una vez (Credenciales): que no se
     * note por el tiempo qué cuentas existen.
     *
     * @return list<Staff|Usuario>
     */
    private function cuentasConClave(string $correo, string $clave): array
    {
        $staff = Staff::where('email', $correo)->first();
        $usuario = Usuario::buscarPorCorreo($correo);
        $intentos = [[$staff, $staff?->password], [$usuario, $usuario?->password]];

        if (config('login_v2.correos_simulados') && ! app()->isProduction()) {
            $simulada = CredencialSimulada::with('cuenta')->where('correo', $correo)->first();
            $intentos[] = [$simulada?->cuenta, $simulada?->clave];
        }

        $intentos = array_filter($intentos, fn (array $intento) => $intento[0] !== null);
        $cuentas = [];

        foreach ($intentos ?: [[null, null]] as [$cuenta, $hash]) {
            if (Credenciales::coinciden($clave, $hash)) {
                // La misma cuenta por su correo real y el simulado cuenta una sola vez.
                $cuentas[LoginUnificadoService::tipoDe($cuenta).':'.$cuenta->getKey()] = $cuenta;
            }
        }

        return array_values($cuentas);
    }
}
