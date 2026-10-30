<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El número de registro de cada copia en Horizon («30727»). Horizon lo guardaba en la nota
 * de la copia (item.notes) y la migración lo dejó como nota pública, que se ve en el
 * portal. Las notas que son solo un número pasan a esta columna; las de texto («CD que
 * acompaña a la tesis») siguen siendo nota pública.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('numero_registro', 50)->nullable()->index();
        });

        DB::statement(<<<'SQL'
            UPDATE ejemplares SET numero_registro = trim(nota_publica), nota_publica = NULL
            WHERE numero_registro IS NULL AND nota_publica ~ '^\s*[0-9]+\s*$'
            SQL);
    }

    public function down(): void
    {
        DB::statement('UPDATE ejemplares SET nota_publica = numero_registro WHERE nota_publica IS NULL AND numero_registro IS NOT NULL');

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropColumn('numero_registro');
        });
    }
};
