<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados_libro_personalizados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->text('descripcion')->nullable();
            // Baja lógica (nunca hard delete, mismo patrón que equipos.activo/staff.activo):
            // un estado personalizado ya usado en el historial de ejemplares no puede
            // desaparecer, solo desactivarse para que no se ofrezca en el selector.
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estados_libro_personalizados');
    }
};
