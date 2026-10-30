<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los libros que se leen en línea (en Horizon, «Libro digital»: los de EBSCO, con su
 * enlace en el 856) no tienen copias que prestar. Su tipo de material los separa de los
 * libros en el listado y en los reportes; la ficha es la de un libro. La migración desde
 * Horizon les asigna este tipo (mapeos.json → itype).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('tipos_material')->where('nombre', 'ilike', 'libro electr_nico')->exists()) {
            DB::table('tipos_material')->insert([
                'nombre' => 'Libro electrónico',
                'plantilla' => 'libro',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tipos_material')
            ->where('nombre', 'Libro electrónico')
            ->whereNotExists(fn ($q) => $q->from('libros')->whereColumn('libros.tipo_material_id', 'tipos_material.id'))
            ->delete();
    }
};
