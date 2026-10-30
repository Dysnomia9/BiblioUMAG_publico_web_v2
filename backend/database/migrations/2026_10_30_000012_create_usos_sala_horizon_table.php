<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada uso de una logia o de un puesto de estudio registrado en Horizon, antes de BiblioUMAG,
 * solo para los reportes de Uso de salas: cuándo, cuánto duró y de qué tipo, carrera y sexo
 * era quien lo pidió. Sin la persona (ni su id ni su documento) ni la logia, que Horizon no
 * distingue. No es historial: no aparece en ninguna ficha. Lo llena la fase usos_salas del ETL,
 * que lo reemplaza entero en cada carga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usos_sala_horizon', function (Blueprint $table) {
            $table->id();
            $table->string('servicio', 10);
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('tipo_usuario', 20)->nullable();
            $table->string('carrera')->nullable();
            $table->string('sexo', 20)->nullable();

            $table->index('fecha');
        });

        DB::statement("ALTER TABLE usos_sala_horizon ADD CONSTRAINT chk_usos_sala_horizon_servicio CHECK (servicio IN ('logia', 'puesto'))");
        DB::statement('ALTER TABLE usos_sala_horizon ADD CONSTRAINT chk_usos_sala_horizon_horas CHECK (hora_fin > hora_inicio)');
    }

    public function down(): void
    {
        Schema::dropIfExists('usos_sala_horizon');
    }
};
