<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los juegos (tipos de material con la ficha de Juego educativo) no salen de la
 * biblioteca: una regla de préstamo «no se presta a domicilio» para todos los usuarios y
 * ubicaciones. Se siguen prestando para uso en sala. La regla se puede editar en
 * Administración → Reglas de préstamo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        foreach (DB::table('tipos_material')->where('plantilla', 'juego_educativo')->pluck('id') as $tipoId) {
            $regla = DB::table('reglas_prestamo')->whereNull('tipo_usuario')->where('tipo_material_id', $tipoId)->whereNull('ubicacion_id');

            if ($regla->exists()) {
                $regla->update(['prestable' => false, 'updated_at' => $ahora]);
            } else {
                DB::table('reglas_prestamo')->insert(['tipo_material_id' => $tipoId, 'prestable' => false, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
    }

    public function down(): void
    {
        DB::table('reglas_prestamo')
            ->whereNull('tipo_usuario')
            ->whereNull('ubicacion_id')
            ->where('prestable', false)
            ->whereIn('tipo_material_id', DB::table('tipos_material')->where('plantilla', 'juego_educativo')->select('id'))
            ->delete();
    }
};
