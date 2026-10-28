<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Bitacora;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lo común a las formas de entrar del login (correo o RUT con contraseña, Google y LDAP):
 * a quién pertenece la identidad ya verificada (al personal, a un usuario o a los dos) y
 * la sesión de Sanctum que le corresponde. Ninguna forma crea cuentas: solo entran las
 * que un administrador ya dio de alta.
 *
 * Una persona puede tener cuenta del personal y cuenta de usuario con el mismo correo (una
 * bibliotecaria que también estudia): en vez de elegir por ella, se le pregunta con cuál
 * entra (`elegir`) y la elección vuelve en `como`.
 */
class LoginUnificadoService
{
    /** Segundos que dura el código que Google deja en la URL; alcanza para elegir la cuenta. */
    private const VIGENCIA_CODIGO = 120;

    /**
     * Las cuentas activas dueñas de ese email: la del personal y la de usuario (por su
     * correo principal o uno adicional), si tiene las dos.
     *
     * @return list<Staff|Usuario>
     */
    public function cuentasPorEmail(string $email): array
    {
        // Google o el directorio pueden devolverlo con mayúsculas; el del personal se guarda en minúscula.
        $staff = Staff::where('email', Str::lower(trim($email)))->first();
        $usuario = Usuario::buscarPorCorreo($email);

        return array_values(array_filter([$staff, $usuario], fn ($cuenta) => $cuenta?->activo));
    }

    /**
     * La sesión de la única cuenta, o las opciones si son dos y todavía no se eligió. Con
     * `$como` ('staff' o 'usuario') queda solo esa; null si no hay ninguna.
     *
     * @param  list<Staff|Usuario>  $cuentas
     * @param  string  $medio  Cómo entró, para la bitácora: «correo», «Google», «LDAP».
     * @return array{tipo: string, token: string, entidad: Staff|Usuario}|array{elegir: list<array{tipo: string, titulo: string, detalle: string}>}|null
     */
    public function resolver(array $cuentas, ?string $como, string $medio): ?array
    {
        if ($como) {
            $cuentas = array_filter($cuentas, fn ($cuenta) => self::tipoDe($cuenta) === $como);
        }
        $cuentas = array_values($cuentas);

        if (! $cuentas) {
            return null;
        }

        if (count($cuentas) > 1) {
            return ['elegir' => array_map(fn ($cuenta) => self::opcion($cuenta), $cuentas)];
        }

        return $this->emitir($cuentas[0], $medio);
    }

    /**
     * Código de un solo uso para volver de Google al frontend: el token de sesión no
     * viaja en la URL (quedaría en el historial y en los logs de nginx o de cualquier
     * proxy); el frontend canjea el código por el token con un POST (canjear()).
     *
     * @param  list<Staff|Usuario>  $cuentas
     */
    public function codigoDeCanje(array $cuentas): string
    {
        $codigo = Str::random(64);

        Cache::put($this->claveCanje($codigo), array_map(
            fn ($cuenta) => ['tipo' => self::tipoDe($cuenta), 'id' => $cuenta->getKey()],
            $cuentas,
        ), self::VIGENCIA_CODIGO);

        return $codigo;
    }

    /**
     * Lo que corresponde al código: la sesión, o las opciones si la persona tiene las dos
     * cuentas y no eligió (el código sigue sirviendo para volver con `$como`). Null si no
     * existe, venció o ya se usó. Las cuentas se vuelven a revisar: pudieron desactivarse
     * entretanto.
     */
    public function canjear(string $codigo, ?string $como): ?array
    {
        $guardadas = Cache::get($this->claveCanje($codigo));
        if (! is_array($guardadas)) {
            return null;
        }

        $cuentas = array_values(array_filter(array_map(
            fn (array $c) => $c['tipo'] === 'staff' ? Staff::find($c['id']) : Usuario::find($c['id']),
            $guardadas,
        ), fn ($cuenta) => $cuenta?->activo));

        $resultado = $this->resolver($cuentas, $como, 'Google');
        if ($resultado && isset($resultado['token'])) {
            Cache::forget($this->claveCanje($codigo));
        }

        return $resultado;
    }

    /**
     * La sesión de esa cuenta, ya autenticada por quien llama. El ingreso del personal
     * queda en la bitácora.
     *
     * @return array{tipo: string, token: string, entidad: Staff|Usuario}
     */
    public function emitir(Staff|Usuario $cuenta, string $medio = 'correo'): array
    {
        $tipo = self::tipoDe($cuenta);

        if ($cuenta instanceof Staff) {
            Bitacora::registrar(
                'sesion.ingresar',
                'Inició sesión en el panel del personal'.($medio === 'correo' ? '' : " (con {$medio})"),
                entidad: $cuenta,
                actor: $cuenta,
            );
        }

        return [
            'tipo' => $tipo,
            'token' => $cuenta->createToken("{$tipo}-token")->plainTextToken,
            'entidad' => $cuenta,
        ];
    }

    public static function tipoDe(Staff|Usuario $cuenta): string
    {
        return $cuenta instanceof Staff ? 'staff' : 'usuario';
    }

    /** Cómo se presenta cada cuenta cuando hay que elegir. */
    private static function opcion(Staff|Usuario $cuenta): array
    {
        if ($cuenta instanceof Staff) {
            return ['tipo' => 'staff', 'titulo' => 'Panel del personal', 'detalle' => $cuenta->rolAsignado?->nombre ?? 'Personal de biblioteca'];
        }

        $donde = $cuenta->carrera ?: $cuenta->unidad;

        return [
            'tipo' => 'usuario',
            'titulo' => 'Portal de la biblioteca',
            'detalle' => Str::ucfirst($cuenta->tipo ?? 'usuario').($donde ? " · {$donde}" : ''),
        ];
    }

    private function claveCanje(string $codigo): string
    {
        return 'login-externo:'.hash('sha256', $codigo);
    }
}
