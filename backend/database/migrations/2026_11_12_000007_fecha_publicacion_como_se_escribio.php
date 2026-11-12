<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha del pie de imprenta (260/264 $c) como se escribió: «c2001», «[19--]»,
 * «1990, c1963». anio_publicacion sigue siendo el año, para ordenar y filtrar; con solo
 * ese número, en unos 11.000 títulos de Horizon el pie de imprenta perdía la «c» del
 * copyright, los corchetes de la fecha supuesta o la segunda fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('fecha_publicacion')->nullable()->after('anio_publicacion');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('fecha_publicacion');
        });
    }
};
