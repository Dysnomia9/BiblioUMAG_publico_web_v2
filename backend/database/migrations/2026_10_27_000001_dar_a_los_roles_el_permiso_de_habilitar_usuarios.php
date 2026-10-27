<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Habilitar a un usuario inactivo (permiso nuevo usuarios.habilitar) lo puede hacer
     * todo el personal que ve el listado de Usuarios: se agrega a los roles que ya lo
     * ven. El rol con acceso total no guarda lista, ya tiene todo el catálogo.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->where('acceso_total', false)->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];

            if (array_intersect(['usuarios.ver', 'usuarios.gestionar'], $permisos) && ! in_array('usuarios.habilitar', $permisos, true)) {
                $permisos[] = 'usuarios.habilitar';
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];

            if (in_array('usuarios.habilitar', $permisos, true)) {
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode(array_values(array_diff($permisos, ['usuarios.habilitar'])))]);
            }
        }
    }
};
