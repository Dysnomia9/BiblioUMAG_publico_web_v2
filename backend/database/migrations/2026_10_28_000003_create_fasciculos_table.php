<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fascículos de las revistas (libros con la plantilla publicacion_seriada): qué
     * números llegaron y cuáles faltan. Si el fascículo se presta, apunta a su copia
     * en `ejemplares`. `referencia_externa` es la clave con que el ETL de
     * horizonte-bibliomag reconoce las filas que ya importó.
     */
    public function up(): void
    {
        Schema::create('fasciculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('libro_id')->constrained('libros')->restrictOnDelete();
            $table->foreignId('ejemplar_id')->nullable()->constrained('ejemplares')->nullOnDelete();
            $table->string('enumeracion')->nullable();
            $table->string('cronologia')->nullable();
            $table->date('fecha_publicacion')->nullable();
            $table->date('fecha_esperada')->nullable();
            $table->date('fecha_recepcion')->nullable();
            $table->string('estado', 20)->default('esperado');
            $table->text('nota')->nullable();
            $table->string('referencia_externa')->nullable()->unique();
            $table->timestamps();

            $table->index(['libro_id', 'estado']);
            $table->index('ejemplar_id');
        });

        DB::statement("ALTER TABLE fasciculos ADD CONSTRAINT chk_fasciculos_estado CHECK (estado IN ('recibido','esperado','reclamado','faltante'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fasciculos');
    }
};
