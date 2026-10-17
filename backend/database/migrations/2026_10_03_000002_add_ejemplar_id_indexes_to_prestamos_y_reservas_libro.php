<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres no indexa solo las claves foráneas, y prestamos.ejemplar_id /
     * reservas_libro.ejemplar_id se agregaron después de la migración que indexó las
     * demás (2024_01_03_000002). Sin estos índices, «¿esta copia está prestada?» —que
     * el listado de libros y el catálogo del portal preguntan por cada copia— recorría
     * todos los préstamos: con 20.000 títulos el catálogo del portal tardaba 96 s.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->index(['ejemplar_id', 'estado']);
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->index(['ejemplar_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropIndex(['ejemplar_id', 'estado']);
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropIndex(['ejemplar_id', 'estado']);
        });
    }
};
