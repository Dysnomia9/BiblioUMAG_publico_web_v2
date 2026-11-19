<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla histórica de credenciales de prueba, retirada por la migración del release
 * del 19 de noviembre de 2026. Se conserva esta migración para las bases existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales_simuladas', function (Blueprint $table) {
            $table->id();
            $table->morphs('cuenta');
            $table->string('correo')->unique();
            $table->string('clave');
            $table->timestamps();

            $table->unique(['cuenta_type', 'cuenta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales_simuladas');
    }
};
