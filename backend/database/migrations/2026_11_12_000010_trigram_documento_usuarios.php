<?php

use App\Support\Busqueda;
use App\Support\Documento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de trigramas para la búsqueda de Usuarios, con las mismas expresiones que la
 * consulta: el documento normalizado (RUT sin puntos ni guion, pasaporte o DNI;
 * Documento::sql) y la carrera sin tildes (Busqueda::sinTildes). Sin ellos, cada búsqueda
 * recorría todos los usuarios (la carrera sola, ~36 ms). Si pg_trgm no está, se sigue sin
 * los índices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            DB::statement('CREATE INDEX IF NOT EXISTS usuarios_documento_trgm ON usuarios USING gin (('.Documento::sql('rut').') gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS usuarios_carrera_trgm ON usuarios USING gin (('.Busqueda::sinTildes('carrera').') gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS usuarios_documento_trgm');
        DB::statement('DROP INDEX IF EXISTS usuarios_carrera_trgm');
    }
};
