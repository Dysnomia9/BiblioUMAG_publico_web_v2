<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas y colección de cada copia, que en Horizon son del ítem y no del título:
     * la nota pública se ve en la ficha de la copia y en el portal, la interna solo
     * la ve el personal. La colección de la copia manda sobre libros.coleccion (en
     * Horizon hay títulos con copias en colecciones distintas); si la copia no
     * tiene, se muestra la del libro. Las carga el ETL de horizonte-bibliomag.
     */
    public function up(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->text('nota_publica')->nullable();
            $table->text('nota_interna')->nullable();
            $table->string('coleccion', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropColumn(['nota_publica', 'nota_interna', 'coleccion']);
        });
    }
};
