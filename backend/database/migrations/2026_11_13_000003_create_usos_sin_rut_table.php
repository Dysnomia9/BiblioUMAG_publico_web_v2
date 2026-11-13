<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libros que alguien sacó del estante, usó y dejó en la mesa sin pedirlos: el mesón
     * los pistolea («Devolución sin RUT») para que quede registro de que se usaron. No son
     * préstamos (no hay persona), por eso van aparte; Reportes los suma como «Sin RUT».
     */
    public function up(): void
    {
        Schema::create('usos_sin_rut', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ejemplar_id')->constrained('ejemplares')->restrictOnDelete();
            // El título con su copia, guardado como en los préstamos: el ranking de Reportes no cambia si el libro se edita.
            $table->string('libro_titulo');
            $table->string('codigo_barras', 64)->nullable();
            $table->timestamp('fecha_hora')->useCurrent()->index();
            $table->string('registrado_por')->nullable();
            $table->foreignId('registrado_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usos_sin_rut');
    }
};
