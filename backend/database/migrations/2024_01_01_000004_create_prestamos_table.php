<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('libro_titulo');
            $table->timestamp('fecha_prestamo')->useCurrent();
            $table->timestamp('fecha_devolucion');
            $table->timestamp('fecha_devolucion_real')->nullable();
            $table->string('estado')->default('activo');
            $table->timestamps();

            $table->index('usuario_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
