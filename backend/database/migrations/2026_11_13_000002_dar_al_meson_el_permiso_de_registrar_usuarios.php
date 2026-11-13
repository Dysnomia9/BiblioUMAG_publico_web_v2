<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crear y corregir usuarios de a uno (permiso nuevo usuarios.registrar) lo hace el
     * mesón desde Préstamos y Entradas: se agrega a los roles que registran entradas o
     * prestan. El rol con acceso total no guarda lista, ya tiene todo el catálogo.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->where('acceso_total', false)->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];

            if (array_intersect(['entradas.registrar', 'prestamos.gestionar', 'usuarios.gestionar'], $permisos) && ! in_array('usuarios.registrar', $permisos, true)) {
                $permisos[] = 'usuarios.registrar';
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];

            if (in_array('usuarios.registrar', $permisos, true)) {
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode(array_values(array_diff($permisos, ['usuarios.registrar'])))]);
            }
        }
    }
};
