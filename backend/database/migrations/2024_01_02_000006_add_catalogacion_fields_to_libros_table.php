<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('clasificacion')->nullable()->after('categoria');
            $table->string('coleccion')->nullable()->after('clasificacion');
            $table->string('editorial')->nullable()->after('coleccion');
            $table->smallInteger('anio_publicacion')->nullable()->after('editorial');
            $table->string('ubicacion')->nullable()->after('anio_publicacion');
            $table->string('tipo_material')->default('libro')->after('ubicacion');
            $table->string('volumen')->nullable()->after('tipo_material');
            $table->text('nota_interna')->nullable()->after('volumen');
            $table->text('nota_publica')->nullable()->after('nota_interna');
            $table->decimal('precio', 10, 2)->nullable()->after('nota_publica');
            $table->string('estado_proceso')->default('inventario')->after('precio');
            $table->date('fecha_inventario')->nullable()->after('estado_proceso');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn([
                'clasificacion',
                'coleccion',
                'editorial',
                'anio_publicacion',
                'ubicacion',
                'tipo_material',
                'volumen',
                'nota_interna',
                'nota_publica',
                'precio',
                'estado_proceso',
                'fecha_inventario',
            ]);
        });
    }
};
