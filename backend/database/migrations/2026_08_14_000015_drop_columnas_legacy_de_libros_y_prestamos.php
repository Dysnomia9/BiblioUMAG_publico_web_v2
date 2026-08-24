<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra el split Libro/Ejemplar: ya se backfilleó todo a `ejemplares` +
 * pivotes `libro_autor`/`libro_categoria` (ver migración anterior), así que
 * las columnas físicas de `libros` y el `libro_id` viejo de `prestamos`
 * quedan huérfanas. down() reconstruye las columnas pero no restaura los
 * datos (ver nota de irreversibilidad en la migración de backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE libros DROP CONSTRAINT chk_libros_estado_proceso');

        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn([
                'codigo_barras',
                'disponible',
                'ubicacion',
                'volumen',
                'precio',
                'estado_proceso',
                'fecha_inventario',
                'autor',
                'categoria',
            ]);
        });

        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('libro_id');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('codigo_barras')->nullable()->after('titulo');
            $table->string('autor')->nullable()->after('isbn');
            $table->string('categoria')->nullable()->after('autor');
            $table->boolean('disponible')->default(true)->after('categoria');
            $table->string('ubicacion')->nullable();
            $table->string('volumen')->nullable();
            $table->decimal('precio', 10, 2)->nullable();
            $table->string('estado_proceso')->default('inventario');
            $table->date('fecha_inventario')->nullable();
        });

        DB::statement("ALTER TABLE libros ADD CONSTRAINT chk_libros_estado_proceso CHECK (estado_proceso IN ('inventario','procesos_tecnicos','por_colocar','en_estante','estanteria_auxiliar','de_baja'))");

        Schema::table('prestamos', function (Blueprint $table) {
            $table->foreignId('libro_id')->nullable()->after('usuario_id')
                ->constrained('libros')->restrictOnDelete();
        });
    }
};
