<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las salas ahora se administran desde la aplicación. Una sala que deja de usarse se da de
     * baja (activa = false), y la base ya no borra en cascada las reservas de una sala
     * eliminada: el historial y los reportes no se pueden perder por eliminar una sala.
     */
    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->boolean('activa')->default(true);
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->dropForeign(['sala_id']);
            $table->foreign('sala_id')->references('id')->on('salas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropForeign(['sala_id']);
            $table->foreign('sala_id')->references('id')->on('salas')->cascadeOnDelete();
        });

        Schema::table('salas', function (Blueprint $table) {
            $table->dropColumn('activa');
        });
    }
};
