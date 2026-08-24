<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unifica una estrategia de cascada inconsistente (prestamos.usuario_id era
     * CASCADE mientras prestamos.libro_id/equipo_id eran SET NULL, y
     * reservas_libro.libro_id era CASCADE para el mismo tipo de relación que
     * prestamos.libro_id). Ninguna de estas tablas es la entidad "padre" —
     * son historial/circulación, y la baja lógica ya existe en el padre
     * (usuarios.activo, libros.estado_proceso='de_baja', equipos.activo).
     * RESTRICT bloquea el borrado a nivel de Postgres incluso ante un DELETE
     * manual, sin depender de que la app respete soft deletes.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->restrictOnDelete();

            $table->dropForeign(['libro_id']);
            $table->foreign('libro_id')->references('id')->on('libros')->restrictOnDelete();

            $table->dropForeign(['equipo_id']);
            $table->foreign('equipo_id')->references('id')->on('equipos')->restrictOnDelete();
        });

        Schema::table('entradas', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->restrictOnDelete();
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->restrictOnDelete();

            $table->dropForeign(['libro_id']);
            $table->foreign('libro_id')->references('id')->on('libros')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();

            $table->dropForeign(['libro_id']);
            $table->foreign('libro_id')->references('id')->on('libros')->nullOnDelete();

            $table->dropForeign(['equipo_id']);
            $table->foreign('equipo_id')->references('id')->on('equipos')->nullOnDelete();
        });

        Schema::table('entradas', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();

            $table->dropForeign(['libro_id']);
            $table->foreign('libro_id')->references('id')->on('libros')->cascadeOnDelete();
        });
    }
};
