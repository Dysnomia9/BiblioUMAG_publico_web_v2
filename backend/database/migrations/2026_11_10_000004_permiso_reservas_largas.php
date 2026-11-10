<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso nuevo «Reservas largas» (salas.sin_limite): reservar una sala desde el panel por
 * más que el máximo del portal, por ejemplo la Sala de Postgrado todo el día. Lo reciben
 * todos los roles del personal que ya existen (el mesón y Atención de público incluidos);
 * los de acceso total lo tienen solos. A un rol que se cree después se le marca en
 * Administración › Personal y roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('acceso_total', false)->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos ?? '[]', true) ?: [];
            if (! in_array('salas.sin_limite', $permisos, true)) {
                $permisos[] = 'salas.sin_limite';
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode(array_values($permisos))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos ?? '[]', true) ?: [];
            if (in_array('salas.sin_limite', $permisos, true)) {
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode(array_values(array_diff($permisos, ['salas.sin_limite'])))]);
            }
        }
    }
};
