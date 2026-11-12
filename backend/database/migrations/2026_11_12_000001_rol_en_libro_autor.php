<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El papel de cada persona en la obra (700 $e): traductor, editor, compilador… Sin rol
 * es autor. El profesor guía de las tesis sigue en su propia columna (libros.profesor_guia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libro_autor', function (Blueprint $table) {
            $table->string('rol', 30)->nullable()->after('orden');
        });
    }

    public function down(): void
    {
        Schema::table('libro_autor', function (Blueprint $table) {
            $table->dropColumn('rol');
        });
    }
};
