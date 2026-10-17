<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Segundo código de barras de cada copia, de 8 dígitos, para la etiqueta de lomo
     * (la que va pegada por fuera, donde se afirman las hojas). El de 14 dígitos es
     * demasiado largo para imprimirlo parado en un lomo angosto. Los dos identifican
     * a la misma copia: el lector puede escanear cualquiera de ellos.
     *
     * Lo asigna la base (una secuencia desde 10000001, sin ceros a la izquierda que
     * Excel o un lector puedan comerse), así que nadie lo tipea ni se repite. Las
     * copias que ya existían lo reciben en el orden en que se catalogaron.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE ejemplares_codigo_lomo_seq START WITH 10000001 MINVALUE 10000001 MAXVALUE 99999999');
        DB::statement('ALTER TABLE ejemplares ADD COLUMN codigo_lomo varchar(8)');
        DB::statement('UPDATE ejemplares SET codigo_lomo = (10000000 + ordenados.n)::text FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM ejemplares) AS ordenados WHERE ejemplares.id = ordenados.id');
        $existentes = DB::table('ejemplares')->count();
        if ($existentes > 0) {
            DB::statement('SELECT setval(?, ?)', ['ejemplares_codigo_lomo_seq', 10000000 + $existentes]);
        }
        DB::statement("ALTER TABLE ejemplares ALTER COLUMN codigo_lomo SET DEFAULT nextval('ejemplares_codigo_lomo_seq')::text");
        DB::statement('ALTER TABLE ejemplares ALTER COLUMN codigo_lomo SET NOT NULL');
        DB::statement('ALTER SEQUENCE ejemplares_codigo_lomo_seq OWNED BY ejemplares.codigo_lomo');
        DB::statement('ALTER TABLE ejemplares ADD CONSTRAINT ejemplares_codigo_lomo_unique UNIQUE (codigo_lomo)');
        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_codigo_lomo CHECK (codigo_lomo ~ '^[0-9]{8}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ejemplares DROP COLUMN IF EXISTS codigo_lomo');
        DB::statement('DROP SEQUENCE IF EXISTS ejemplares_codigo_lomo_seq');
    }
};
