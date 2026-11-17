<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Conserva los ids y todo el historial de préstamos de ambas salas.
        foreach (['SEM' => 'SM1', 'SEM2' => 'SM2'] as $anterior => $codigo) {
            DB::table('salas')->whereIn('codigo', [$anterior, $codigo])->update([
                'codigo' => $codigo, 'nombre' => $codigo,
                'solo_administracion' => true, 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('roles')->whereIn('clave', ['asistente', 'bibliotecario'])->get() as $rol) {
            $permisos = json_decode($rol->permisos, true);
            $permisos[] = 'salas.seminarios';
            DB::table('roles')->where('id', $rol->id)->update([
                'permisos' => json_encode(array_values(array_unique($permisos))), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['SM1' => ['SEM', 'Seminario 1'], 'SM2' => ['SEM2', 'Seminario 2']] as $codigo => [$anterior, $nombre]) {
            DB::table('salas')->where('codigo', $codigo)->update([
                'codigo' => $anterior, 'nombre' => $nombre, 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('roles')->whereIn('clave', ['asistente', 'bibliotecario'])->get() as $rol) {
            DB::table('roles')->where('id', $rol->id)->update([
                'permisos' => json_encode(array_values(array_diff(json_decode($rol->permisos, true), ['salas.seminarios']))),
                'updated_at' => now(),
            ]);
        }
    }
};
