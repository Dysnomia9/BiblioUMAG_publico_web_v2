<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa la copia física ("ejemplar") del registro bibliográfico ("libro" —
 * ver siguientes migraciones de esta tanda, que le quitan a `libros` las
 * columnas que se mudan aquí). Antes 1 fila `libros` = 1 copia física, sin
 * soportar múltiples copias del mismo título (ver checklist de Horizon en
 * CLAUDE.md, punto "Gestión de ejemplares"). Ahora un `libro` puede tener
 * N `ejemplares`, cada uno con su propio código de barras y estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ejemplares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('libro_id')->constrained('libros')->restrictOnDelete();
            $table->unsignedTinyInteger('numero_copia')->default(1);
            $table->string('codigo_barras')->unique();
            $table->boolean('disponible')->default(true);
            $table->string('estado_proceso')->default('inventario');
            $table->foreignId('estado_personalizado_id')->nullable()
                ->constrained('estados_libro_personalizados')->nullOnDelete();
            $table->string('ubicacion')->nullable();
            $table->string('volumen')->nullable();
            $table->decimal('precio', 10, 2)->nullable();
            $table->date('fecha_inventario')->nullable();
            $table->timestamps();

            $table->index('libro_id');
            $table->index('estado_proceso');
        });

        // Mismo criterio que 2024_01_03_000001 (Postgres no tiene ->check() nativo
        // en el Schema Builder): 6 estados originales de libros.estado_proceso +
        // 'coleccion_movil' (fijo, pedido explícito) + 'personalizado' (genérico,
        // requiere estado_personalizado_id seteado — ver EjemplarController).
        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_estado_proceso CHECK (estado_proceso IN ('inventario','procesos_tecnicos','por_colocar','en_estante','estanteria_auxiliar','de_baja','coleccion_movil','personalizado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ejemplares');
    }
};
