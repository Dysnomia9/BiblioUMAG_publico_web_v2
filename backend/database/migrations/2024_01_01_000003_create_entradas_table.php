<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamp('fecha_hora_entrada')->useCurrent();
            $table->timestamp('fecha_hora_salida')->nullable();
            $table->string('via')->default('manual');
            $table->timestamps();

            $table->index('usuario_id');
            $table->index('fecha_hora_entrada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entradas');
    }
};
