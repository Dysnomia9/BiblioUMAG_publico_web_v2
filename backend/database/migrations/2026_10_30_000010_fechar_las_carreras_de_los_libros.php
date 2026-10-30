<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desde cuándo cada carrera está asociada al título (libro_carrera.created_at, solo como
 * registro: no se muestra) no se guardaba hasta ahora. Las asociaciones que ya existían toman
 * la de la última vez que se catalogó o editó la ficha según la bitácora: la carrera se asoció
 * ese día o antes. Las que no tienen registro quedan sin fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE libro_carrera lc SET created_at = b.ultima, updated_at = b.ultima
            FROM (
                SELECT entidad_id, max(created_at) AS ultima FROM bitacora
                WHERE entidad_tipo = 'Libro' AND accion IN ('libros.catalogar', 'libros.editar')
                GROUP BY entidad_id
            ) b
            WHERE lc.libro_id = b.entidad_id AND lc.created_at IS NULL
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: no queda registro de cuáles no tenían fecha.
    }
};
