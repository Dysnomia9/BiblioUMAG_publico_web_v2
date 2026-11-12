<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dos copias de un mismo título no pueden tener el mismo número. Si ya hay repetidas
 * (algunas llegaron así desde Horizon), la más nueva pasa al número siguiente libre del libro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH repetidas AS (
                SELECT id, libro_id, row_number() OVER (PARTITION BY libro_id, numero_copia ORDER BY id) AS n
                FROM ejemplares
            ), a_mover AS (
                SELECT r.id, r.libro_id,
                       (SELECT max(numero_copia) FROM ejemplares x WHERE x.libro_id = r.libro_id)
                         + row_number() OVER (PARTITION BY r.libro_id ORDER BY r.id) AS nuevo
                FROM repetidas r WHERE r.n > 1
            )
            UPDATE ejemplares e SET numero_copia = a_mover.nuevo FROM a_mover WHERE e.id = a_mover.id
        SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ejemplares_libro_numero_copia_unique ON ejemplares (libro_id, numero_copia)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ejemplares_libro_numero_copia_unique');
    }
};
