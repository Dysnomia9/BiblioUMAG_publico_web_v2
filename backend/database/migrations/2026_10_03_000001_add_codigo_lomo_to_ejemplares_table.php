<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segundo código de barras de cada copia, de 8 dígitos, para la etiqueta de lomo
     * (la que va pegada por fuera, donde se afirman las hojas). El de 14 dígitos es
     * demasiado largo para imprimirlo parado en un lomo angosto. Los dos identifican
     * a la misma copia: el lector puede escanear cualquiera de ellos.
     *
     * MySQL no tiene secuencias: el próximo número sale de la tabla `contadores`
     * (App\Support\Contador, que la bloquea mientras lo toma), desde 10000001 y sin
     * ceros a la izquierda que Excel o un lector puedan comerse. Lo asigna el modelo
     * Ejemplar al crearse la copia, así que nadie lo tipea ni se repite. Las copias que
     * ya existían lo reciben acá, en el orden en que se catalogaron.
     */
    public function up(): void
    {
        Schema::create('contadores', function (Blueprint $table) {
            $table->string('nombre', 60)->primary();
            $table->unsignedBigInteger('valor');
        });

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('codigo_lomo', 8)->nullable()->after('codigo_barras');
        });

        $ultimo = 10000000;
        DB::table('ejemplares')->orderBy('id')->select('id')->chunkById(500, function ($ejemplares) use (&$ultimo) {
            foreach ($ejemplares as $ejemplar) {
                DB::table('ejemplares')->where('id', $ejemplar->id)->update(['codigo_lomo' => (string) ++$ultimo]);
            }
        });
        DB::table('contadores')->insert(['nombre' => 'codigo_lomo', 'valor' => $ultimo]);

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('codigo_lomo', 8)->nullable(false)->change();
            $table->unique('codigo_lomo');
        });
        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_codigo_lomo CHECK (codigo_lomo REGEXP '^[0-9]{8}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ejemplares DROP CONSTRAINT chk_ejemplares_codigo_lomo');
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropUnique(['codigo_lomo']);
            $table->dropColumn('codigo_lomo');
        });
        Schema::dropIfExists('contadores');
    }
};
