<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ejemplar_estado_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ejemplar_id')->constrained('ejemplares')->restrictOnDelete();
            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            // Nombres de FK explícitos: los que arma Laravel pasan los 64 caracteres
            // que acepta MySQL.
            $table->foreignId('estado_personalizado_anterior_id')->nullable()
                ->constrained('estados_libro_personalizados', 'id', 'ejemplar_historial_estado_pers_anterior_foreign')->nullOnDelete();
            $table->foreignId('estado_personalizado_nuevo_id')->nullable()
                ->constrained('estados_libro_personalizados', 'id', 'ejemplar_historial_estado_pers_nuevo_foreign')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            // Agrupa las filas escritas por una misma operación de cambio masivo — null
            // en un cambio individual (EjemplarController::cambiarEstado).
            $table->uuid('lote_id')->nullable();
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->index('ejemplar_id');
            $table->index('lote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ejemplar_estado_historial');
    }
};
