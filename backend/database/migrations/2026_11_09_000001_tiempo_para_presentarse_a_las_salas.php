<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El tiempo para presentarse a una sala deja de estar fijo en config/salas.php: hay un
     * valor general (configuracion_institucional) y fechas con otro valor (la semana de
     * pruebas). Cada reserva guarda el suyo; las que ya existen se hicieron con 15 minutos.
     */
    public function up(): void
    {
        Schema::table('configuracion_institucional', function (Blueprint $table) {
            $table->unsignedSmallInteger('plazo_confirmacion_sala')->default(15);
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->unsignedSmallInteger('plazo_confirmacion_minutos')->default(15);
        });

        Schema::create('periodos_plazo_sala', function (Blueprint $table) {
            $table->id();
            $table->date('desde');
            $table->date('hasta');
            $table->unsignedSmallInteger('minutos');
            $table->string('motivo')->nullable();
            $table->timestamps();

            $table->index(['desde', 'hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_plazo_sala');

        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('plazo_confirmacion_minutos');
        });

        Schema::table('configuracion_institucional', function (Blueprint $table) {
            $table->dropColumn('plazo_confirmacion_sala');
        });
    }
};
