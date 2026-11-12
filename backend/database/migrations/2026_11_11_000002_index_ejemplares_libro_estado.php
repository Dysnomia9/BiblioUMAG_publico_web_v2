<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El catálogo del portal pregunta por cada título «¿tiene copias en estante?, ¿cuántas?,
     * ¿cuántas disponibles?», y al buscar cuenta todos los que coinciden. Con índices
     * separados por libro y por estado, ese conteo recorría las copias: con 43.000 títulos
     * tardaba ~120 ms por búsqueda; con este índice combinado, ~44 ms.
     */
    public function up(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->index(['libro_id', 'estado_proceso', 'disponible']);
        });
    }

    public function down(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropIndex(['libro_id', 'estado_proceso', 'disponible']);
        });
    }
};
