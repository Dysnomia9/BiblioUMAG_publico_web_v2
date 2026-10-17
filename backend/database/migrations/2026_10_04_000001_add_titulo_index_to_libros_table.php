<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El listado de libros, el historial y el catálogo del portal se muestran por orden
     * alfabético y por páginas. Con este índice la base recorre los títulos ya
     * ordenados y se detiene al completar la página, en vez de ordenar el catálogo
     * entero en cada petición: con 20.000 títulos, la página del portal pasó de 76 a
     * 27 ms y una página avanzada del listado, de 30 a 2 ms.
     */
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->index('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropIndex(['titulo']);
        });
    }
};
