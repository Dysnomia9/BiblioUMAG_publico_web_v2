<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correos y claves simulados del login v2 (ver config/login_v2.php): una fila por cuenta
 * del personal o usuario, aparte de sus credenciales reales.
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
