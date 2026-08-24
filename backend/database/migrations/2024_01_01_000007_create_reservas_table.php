<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sala_id')->constrained('salas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('nombre_usuario');
            $table->string('rut_usuario');
            $table->date('fecha');
            $table->unsignedTinyInteger('hora_inicio');
            $table->unsignedTinyInteger('hora_fin');
            $table->string('estado')->default('activa');
            $table->timestamps();

            $table->index(['sala_id', 'fecha']);
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};
