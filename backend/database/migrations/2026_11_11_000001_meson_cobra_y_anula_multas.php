<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atención de público y Bibliotecario/a pueden registrar el pago de una multa y anularla.
 * Bibliotecario/a los trae de fábrica, pero se revisa igual por si le quitaron alguno en
 * Administración › Personal y roles. Cursar multas manuales sigue siendo aparte.
 */
return new class extends Migration
{
    private const PERMISOS = ['multas.cobrar', 'multas.anular'];

    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('clave', ['asistente', 'bibliotecario'])->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $rol->id)->update([
                'permisos' => json_encode(array_values(array_unique([...$permisos, ...self::PERMISOS]))),
            ]);
        }
    }

    public function down(): void
    {
        // Bibliotecario/a ya los tenía de fábrica: solo se le quitan a Atención de público.
        foreach (DB::table('roles')->where('clave', 'asistente')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode($rol->permisos ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $rol->id)->update([
                'permisos' => json_encode(array_values(array_diff($permisos, self::PERMISOS))),
            ]);
        }
    }
};
