<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempo máximo de préstamo de cada categoría de recursos de apoyo: al prestar, la
 * devolución queda a esa cantidad de horas (null: sin plazo, como antes). Se edita en
 * Administración → Catálogos. Notebooks, audífonos y cargadores, 3 horas; la bicicleta,
 * 24, y pasa a llamarse «Bicicleta + kit» (se presta con casco y candado).
 */
return new class extends Migration
{
    private const INDICACIONES_BICICLETA_ANTES = 'Se devuelve el mismo día, antes del cierre, con su casco y candado';

    private const INDICACIONES_BICICLETA = 'Se devuelve con su kit: casco y candado';

    public function up(): void
    {
        Schema::table('categorias_recurso', function (Blueprint $table) {
            $table->unsignedSmallInteger('horas_maximas')->nullable()->after('limite_por_persona');
        });

        DB::table('categorias_recurso')->whereIn('clave', ['notebook', 'audifonos', 'cargador'])->update(['horas_maximas' => 3]);
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->update(['horas_maximas' => 24]);
        // Solo si conservan el nombre y las indicaciones de fábrica: lo que el personal ya cambió no se toca.
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('nombre', 'Bicicleta')
            ->update(['nombre' => 'Bicicleta + kit', 'nombre_plural' => 'Bicicletas + kit']);
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('indicaciones', self::INDICACIONES_BICICLETA_ANTES)
            ->update(['indicaciones' => self::INDICACIONES_BICICLETA]);

        // Lo que está prestado ahora queda con su hora de devolución, contada desde que se prestó.
        DB::statement(<<<'SQL'
            UPDATE prestamos p SET fecha_devolucion = p.fecha_prestamo + make_interval(hours => c.horas_maximas)
            FROM categorias_recurso c
            WHERE p.tipo_item = c.clave AND p.estado = 'activo' AND p.fecha_devolucion IS NULL AND c.horas_maximas IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('nombre', 'Bicicleta + kit')
            ->update(['nombre' => 'Bicicleta', 'nombre_plural' => 'Bicicletas']);
        DB::table('categorias_recurso')->where('clave', 'bicicleta')->where('indicaciones', self::INDICACIONES_BICICLETA)
            ->update(['indicaciones' => self::INDICACIONES_BICICLETA_ANTES]);

        Schema::table('categorias_recurso', function (Blueprint $table) {
            $table->dropColumn('horas_maximas');
        });
    }
};
