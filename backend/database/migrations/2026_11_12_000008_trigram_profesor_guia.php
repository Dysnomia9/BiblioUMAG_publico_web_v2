<?php

use App\Support\Busqueda;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice de trigramas del profesor guía, con la misma expresión que la búsqueda
 * (Busqueda::sinTildes, como en 2026_10_03_000003). Sin él, cada palabra buscada en el
 * Listado recorría los 47.000 libros (~25 ms por palabra). Si pg_trgm no está, se sigue
 * sin el índice (la búsqueda funciona igual, más lenta).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            DB::statement('CREATE INDEX IF NOT EXISTS libros_profesor_guia_trgm ON libros USING gin (('
                .Busqueda::sinTildes('profesor_guia').') gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS libros_profesor_guia_trgm');
    }
};
