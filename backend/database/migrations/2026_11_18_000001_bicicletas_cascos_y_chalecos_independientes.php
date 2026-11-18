<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('nombre', 'Bicicleta + kit')
            ->update(['nombre' => 'Bicicleta', 'nombre_plural' => 'Bicicletas', 'updated_at' => $ahora]);
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->whereIn('indicaciones', [
            'Se devuelve con su kit: casco y candado', 'Se devuelve con su kit: casco y chaleco',
        ])->update(['indicaciones' => 'Se registra por su propio código; el casco y el chaleco se registran por separado', 'updated_at' => $ahora]);

        foreach ([['chaleco', 'Chaleco', 'Chalecos', 'amber'], ['casco', 'Casco', 'Cascos', 'sky']] as [$clave, $nombre, $plural, $color]) {
            DB::table('categorias_recurso')->insertOrIgnore([
                'clave' => $clave, 'nombre' => $nombre, 'nombre_plural' => $plural,
                'icono' => 'caja', 'color' => $color, 'limite_por_persona' => 1, 'horas_maximas' => 24,
                'indicaciones' => 'Se registra por su propio código de barras', 'activo' => true,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('categorias_recurso')->where('clave', $clave)->where('indicaciones', 'Accesorio de bicicleta; devolver con la bicicleta correspondiente')
                ->update(['indicaciones' => 'Se registra por su propio código de barras', 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('nombre', 'Bicicleta')->where('nombre_plural', 'Bicicletas')
            ->update(['nombre' => 'Bicicleta + kit', 'nombre_plural' => 'Bicicletas + kit']);
        // Las categorías de casco y chaleco se conservan: pueden tener recursos y préstamos.
    }
};
