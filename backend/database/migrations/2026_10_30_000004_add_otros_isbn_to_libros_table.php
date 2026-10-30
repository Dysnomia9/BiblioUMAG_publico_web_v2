<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los demás ISBN de un título (MARC 020 repetido), separados por «; »: el de la
     * colección o la obra completa a la que pertenece (los códigos de Legal Publishing
     * llevan cada uno el suyo y además el de la colección), o el que comparte con otros
     * tomos. `isbn` es único y lleva el propio; estos se repiten entre títulos y sirven
     * para encontrarlos todos al buscar ese ISBN. Con índices de trigramas, como `isbn`
     * (migración add_trigram_indexes_para_busquedas): si pg_trgm no está, sigue sin ellos.
     */
    private const INDICES = [
        ['otros_isbn', 'libros_otros_isbn_trgm'],
        ["replace(otros_isbn, '-', '')", 'libros_otros_isbn_sin_guiones_trgm'],
    ];

    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('otros_isbn', 500)->nullable()->after('isbn');
        });

        if (! DB::table('pg_extension')->where('extname', 'pg_trgm')->exists()) {
            Log::warning('Sin pg_trgm: libros.otros_isbn queda sin índice de búsqueda.');

            return;
        }
        foreach (self::INDICES as [$expresion, $nombre]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$nombre} ON libros USING gin (({$expresion}) gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as [, $nombre]) {
            DB::statement("DROP INDEX IF EXISTS {$nombre}");
        }
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('otros_isbn');
        });
    }
};
