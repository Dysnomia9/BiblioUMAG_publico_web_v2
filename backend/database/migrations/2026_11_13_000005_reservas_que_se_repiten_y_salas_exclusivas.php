<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - salas.reserva_exclusiva: una sala de la administración que reserva solo quien tiene el
 *   permiso salas.exclusivas (y los administradores), no todo el que tiene salas.administracion.
 * - reservas.serie: las reservas creadas juntas al repetir una semana a semana («todos los
 *   lunes del semestre de 10:00 a 12:00») comparten este código, para verlas y cancelarlas juntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->boolean('reserva_exclusiva')->default(false)->after('solo_administracion');
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->uuid('serie')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn('serie'));
        Schema::table('salas', fn (Blueprint $table) => $table->dropColumn('reserva_exclusiva'));
    }
};
