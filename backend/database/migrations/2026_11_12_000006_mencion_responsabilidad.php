<?php

use App\Support\Busqueda;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La mención de responsabilidad (245 $c): «Margot C. Howe, Shara L. Schwartzberg», como
 * figura en la portada. En unos 2.800 títulos de Horizon hay personas que solo están ahí
 * (sin un 700), así que se muestra en la ficha y se busca como el título.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->text('mencion_responsabilidad')->nullable()->after('titulo_alternativo');
        });

        // Índice de trigramas como el del título (migración 2026_10_03_000003), si pg_trgm está
        if (DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            DB::statement('CREATE INDEX IF NOT EXISTS libros_mencion_responsabilidad_trgm ON libros USING gin (('
                .Busqueda::sinTildes('mencion_responsabilidad').') gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS libros_mencion_responsabilidad_trgm');
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('mencion_responsabilidad');
        });
    }
};
