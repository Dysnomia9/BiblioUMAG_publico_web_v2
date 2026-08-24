<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;

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
    /** @return array{tipo: string, token: string, entidad: Model}|null */
    public function porEmail(string $email): ?array
    {
        $staff = Staff::where('email', $email)->first();
        if ($staff) {
            return $staff->activo ? $this->emitir('staff', 'staff-token', $staff) : null;
        }

        $usuario = Usuario::where('email', $email)->first();
        if ($usuario) {
            return $usuario->activo ? $this->emitir('usuario', 'usuario-token', $usuario) : null;
        }

        return null;
    }

    /** @return array{tipo: string, token: string, entidad: Model} */
    private function emitir(string $tipo, string $nombreToken, Model $entidad): array
    {
        return [
            'tipo' => $tipo,
            'token' => $entidad->createToken($nombreToken)->plainTextToken,
            'entidad' => $entidad,
        ];
    }
}
