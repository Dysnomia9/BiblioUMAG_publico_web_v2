<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «¿Esta copia está prestada / reservada?» —que el listado de libros y el catálogo
     * del portal preguntan por cada copia— se responde con este índice sin leer las
     * filas: ejemplar_id y estado están los dos en él. (Sin índice en ejemplar_id, con
     * 20.000 títulos el catálogo del portal tardaba 96 s; InnoDB ya indexaba la FK
     * sola, y este índice la reemplaza.)
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
        // Al crear el índice compuesto, MySQL descartó el que había creado solo para la
        // FK de ejemplar_id; sin uno que lo reemplace no deja borrar el compuesto.
        Schema::table('prestamos', function (Blueprint $table) {
            $table->index('ejemplar_id');
            $table->dropIndex(['ejemplar_id', 'estado']);
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->index('ejemplar_id');
            $table->dropIndex(['ejemplar_id', 'estado']);
        });
    }
};
