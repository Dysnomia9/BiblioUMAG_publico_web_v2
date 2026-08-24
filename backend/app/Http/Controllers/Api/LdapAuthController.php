<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoginUnificadoService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LdapRecord\Auth\BindException;
use LdapRecord\Connection;
use LdapRecord\LdapRecordException;

/**
 * Login LDAP institucional para LoginV2View.vue. Patrón "search + bind": conecta
 * con la cuenta de servicio (LDAP_BIND_USERNAME/PASSWORD), busca al usuario por
 * LDAP_USER_ATTRIBUTE, y recién ahí intenta el bind con la contraseña que
 * ingresó — no asume un formato de DN fijo para el usuario final, porque no se
 * conoce todavía el esquema real del directorio de la UMAG. Sin LDAP_HOST
 * configurado (ver .env.example), responde 503 antes de intentar conectar.
 */
class LdapAuthController extends Controller
{
    public function __construct(private LoginUnificadoService $loginUnificado)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'usuario' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $config = config('ldap.connections.'.config('ldap.default'));

        if (empty($config['hosts'])) {
            return response()->json(['message' => 'El login institucional (LDAP) todavía no está configurado.'], 503);
        }

        $connection = new Connection([
            'hosts' => $config['hosts'],
            'port' => $config['port'],
            'base_dn' => $config['base_dn'],
            'timeout' => $config['timeout'],
            'use_tls' => $config['use_tls'],
            'use_starttls' => $config['use_starttls'],
            'use_sasl' => $config['use_sasl'],
            'sasl_options' => $config['sasl_options'],
        ]);

        try {
            $connection->connect($config['username'], $config['password']);

            $entrada = $connection->query()
                ->where(config('ldap.attributes.user'), '=', $data['usuario'])
                ->first();

            if (! $entrada) {
                throw ValidationException::withMessages([
                    'usuario' => ['Las credenciales no coinciden con ningún registro.'],
                ]);
            }

            // El bind exitoso ES la verificación de la contraseña — LDAP no expone
            // contraseñas para comparar a mano, hay que reconectar como el usuario.
            $connection->connect($entrada['dn'], $data['password']);
        } catch (BindException $e) {
            throw ValidationException::withMessages([
                'usuario' => ['Las credenciales no coinciden con ningún registro.'],
            ]);
        } catch (LdapRecordException $e) {
            return response()->json(['message' => 'No se pudo conectar con el servidor LDAP institucional.'], 503);
        }

        $email = $entrada[config('ldap.attributes.email')][0] ?? null;

        if (! $email) {
            throw ValidationException::withMessages([
                'usuario' => ['Tu cuenta LDAP no tiene un correo asociado — contacta a un administrador.'],
            ]);
        }

        $resultado = $this->loginUnificado->porEmail($email);

        if (! $resultado) {
            throw ValidationException::withMessages([
                'usuario' => ['Tu cuenta institucional es válida, pero no está habilitada en Biblioteca UMAG. Contacta a un administrador.'],
            ]);
        }

        return response()->json([
            'tipo' => $resultado['tipo'],
            'token' => $resultado['token'],
        ]);
    }
}
