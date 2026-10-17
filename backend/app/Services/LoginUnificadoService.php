<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Punto compartido entre los proveedores de login externo de LoginV2View.vue
 * (Google, LDAP): dado un email ya verificado por el proveedor, busca a quién
 * pertenece en el sistema (Staff o Usuario) y emite el token de Sanctum
 * correspondiente. Ninguno de los dos providers crea cuentas nuevas — solo
 * autentican identidades que ya existen acá, para que una cuenta de Google/LDAP
 * cualquiera no se auto-provisione como staff/usuario sin que un admin la haya
 * dado de alta antes.
 */
class LoginUnificadoService
{
    /** Segundos que dura el código que Google deja en la URL antes de canjearse. */
    private const VIGENCIA_CODIGO = 60;

    /** @return array{tipo: string, token: string, entidad: Model}|null */
    public function porEmail(string $email): ?array
    {
        $cuenta = $this->cuentaPorEmail($email);

        return $cuenta ? $this->emitir($cuenta) : null;
    }

    /** La cuenta activa dueña de ese email (primero el personal, después los usuarios), o null. */
    public function cuentaPorEmail(string $email): Staff|Usuario|null
    {
        $staff = Staff::where('email', $email)->first();
        if ($staff) {
            return $staff->activo ? $staff : null;
        }

        $usuario = Usuario::where('email', $email)->first();

        return $usuario?->activo ? $usuario : null;
    }

    /**
     * Código de un solo uso para volver de Google al frontend: el token de sesión no
     * viaja en la URL (quedaría en el historial y en los logs de nginx o de cualquier
     * proxy); el frontend canjea el código por el token con un POST (canjear()).
     */
    public function codigoDeCanje(Staff|Usuario $cuenta): string
    {
        $codigo = Str::random(64);

        Cache::put($this->claveCanje($codigo), [
            'tipo' => $cuenta instanceof Staff ? 'staff' : 'usuario',
            'id' => $cuenta->getKey(),
        ], self::VIGENCIA_CODIGO);

        return $codigo;
    }

    /**
     * El token de la cuenta a la que pertenece el código, o null si no existe, venció
     * o ya se usó (se borra al canjearlo). La cuenta se vuelve a revisar: pudo
     * desactivarse entretanto.
     *
     * @return array{tipo: string, token: string, entidad: Model}|null
     */
    public function canjear(string $codigo): ?array
    {
        $datos = Cache::pull($this->claveCanje($codigo));
        if (! $datos) {
            return null;
        }

        $cuenta = $datos['tipo'] === 'staff' ? Staff::find($datos['id']) : Usuario::find($datos['id']);

        return $cuenta?->activo ? $this->emitir($cuenta) : null;
    }

    /** @return array{tipo: string, token: string, entidad: Model} */
    private function emitir(Staff|Usuario $entidad): array
    {
        $tipo = $entidad instanceof Staff ? 'staff' : 'usuario';

        return [
            'tipo' => $tipo,
            'token' => $entidad->createToken("{$tipo}-token")->plainTextToken,
            'entidad' => $entidad,
        ];
    }

    private function claveCanje(string $codigo): string
    {
        return 'login-externo:'.hash('sha256', $codigo);
    }
}
