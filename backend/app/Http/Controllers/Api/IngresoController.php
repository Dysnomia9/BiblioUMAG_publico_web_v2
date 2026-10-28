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
 * El login único, para el personal y los usuarios por igual: correo (o RUT, pasaporte o
 * DNI) y contraseña. El servidor busca de quién son y responde a qué sesión corresponden;
 * si la misma persona tiene cuenta del personal y de usuario con esa clave, le pregunta con
 * cuál entra (LoginUnificadoService::resolver()).
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
            'identificador' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'como' => ['nullable', Rule::in(['staff', 'usuario'])],
        ], [
            'identificador.required' => 'Ingresa tu correo institucional o tu RUT.',
            'password.required' => 'Ingresa tu contraseña.',
        ]);

        $cuentas = $this->cuentasConClave(trim($data['identificador']), $data['password']);

        if (! $cuentas) {
            throw ValidationException::withMessages(['identificador' => ['El correo o RUT y la contraseña no coinciden.']]);
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
     * Las cuentas de ese correo o documento cuya contraseña es la que se escribió: la del
     * personal (por su correo), la del usuario (por su correo, uno adicional o su RUT) y, en
     * desarrollo, la del correo simulado. Sin ninguna, la clave igual se compara una vez
     * (Credenciales): que no se note por el tiempo qué cuentas existen.
     *
     * @return list<Staff|Usuario>
     */
    private function cuentasConClave(string $identificador, string $clave): array
    {
        $intentos = [];

        if (str_contains($identificador, '@')) {
            $correo = Str::lower($identificador);
            $staff = Staff::where('email', $correo)->first();
            $usuario = Usuario::buscarPorCorreo($correo);
            $intentos[] = [$staff, $staff?->password];
            $intentos[] = [$usuario, $usuario?->password];

            if (config('login_v2.correos_simulados') && ! app()->isProduction()) {
                $simulada = CredencialSimulada::with('cuenta')->where('correo', $correo)->first();
                $intentos[] = [$simulada?->cuenta, $simulada?->clave];
            }
        } else {
            $usuario = Usuario::buscarPorIdentificador($identificador);
            $intentos[] = [$usuario, $usuario?->password];
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
