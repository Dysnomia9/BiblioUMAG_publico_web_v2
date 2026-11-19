<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credenciales de prueba separadas: no son los correos ni las claves reales.
        Schema::dropIfExists('credenciales_simuladas');
    }

    public function down(): void
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
};
