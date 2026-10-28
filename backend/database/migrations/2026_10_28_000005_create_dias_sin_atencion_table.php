<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feriados, vacaciones y cierres, además de los días que config/biblioteca.php
     * tiene cerrados cada semana. Sin ubicación, la biblioteca entera no atiende;
     * con ubicación, solo esa (y lo que está ahí). Una devolución no puede caer en
     * estos días y no se cobra multa por ellos (App\Support\HorarioBiblioteca).
     *
     * Único con NULLS NOT DISTINCT, igual que reglas_prestamo: un solo «toda la
     * biblioteca» por fecha.
     */
    public function up(): void
    {
        Schema::create('dias_sin_atencion', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            $table->string('motivo')->nullable();
            $table->timestamps();

            $table->index('ubicacion_id');
        });

        DB::statement('CREATE UNIQUE INDEX dias_sin_atencion_fecha_ubicacion_id_unique ON dias_sin_atencion (fecha, ubicacion_id) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_sin_atencion');
    }
};
