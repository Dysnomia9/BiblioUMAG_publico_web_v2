<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Nota de contenido (MARC 505): el índice de capítulos o de volúmenes de la obra. */
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->text('contenido')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('contenido');
        });
    }
};
