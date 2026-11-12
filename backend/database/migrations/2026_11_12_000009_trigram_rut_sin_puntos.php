<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice de trigramas del RUT sin puntos ni guion, con la misma expresión que la búsqueda
 * de Préstamos («11358982» encuentra «11.358.982-5»). Sin él, cada búsqueda con dígitos
 * recorría todos los usuarios en la lista y en cada contador de pestaña. Si pg_trgm no
 * está, se sigue sin el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            DB::statement("CREATE INDEX IF NOT EXISTS usuarios_rut_sin_puntos_trgm ON usuarios USING gin ((regexp_replace(rut, '[^0-9kK]', '', 'g')) gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS usuarios_rut_sin_puntos_trgm');
    }
};
