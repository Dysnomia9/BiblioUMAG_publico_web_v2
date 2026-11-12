<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N.º de ficha: un número único por título que pone el sistema, para buscar la ficha y
 * nombrarla («ficha 64534»). Las que vienen de Horizon conservan su bib#, el que ya
 * conocían allá; las demás (y las que se creen de aquí en adelante) siguen desde el
 * más alto, con la secuencia libros_numero_ficha_seq. El ETL pone el bib# al cargar y
 * deja la secuencia al día (etl_horizon_biblioumag.py, fase catálogo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function ($table) {
            $table->unsignedBigInteger('numero_ficha')->nullable()->after('id');
        });

        $hayHorizon = DB::selectOne("SELECT to_regclass('horizon_etl.libro') IS NOT NULL AS existe")->existe;
        if ($hayHorizon) {
            DB::statement('UPDATE libros l SET numero_ficha = m.bib_num FROM horizon_etl.libro m WHERE m.libro_id = l.id');
        }

        // Las demás, en el orden en que se crearon, después del número más alto.
        DB::statement('
            UPDATE libros l SET numero_ficha = s.numero
            FROM (SELECT id, (SELECT COALESCE(MAX(numero_ficha), 0) FROM libros) + ROW_NUMBER() OVER (ORDER BY id) AS numero
                  FROM libros WHERE numero_ficha IS NULL) s
            WHERE s.id = l.id');

        DB::statement('CREATE SEQUENCE libros_numero_ficha_seq OWNED BY libros.numero_ficha');
        DB::statement("SELECT setval('libros_numero_ficha_seq', COALESCE((SELECT MAX(numero_ficha) FROM libros), 0) + 1, false)");
        DB::statement("ALTER TABLE libros ALTER COLUMN numero_ficha SET DEFAULT nextval('libros_numero_ficha_seq')");
        DB::statement('ALTER TABLE libros ALTER COLUMN numero_ficha SET NOT NULL');
        DB::statement('ALTER TABLE libros ADD CONSTRAINT libros_numero_ficha_unique UNIQUE (numero_ficha)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE libros DROP CONSTRAINT IF EXISTS libros_numero_ficha_unique');
        Schema::table('libros', function ($table) {
            $table->dropColumn('numero_ficha');
        });
    }
};
