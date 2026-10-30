<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo entró cada título y cada copia a Horizon, el sistema anterior. La llena la
     * migración desde Horizon y no se edita; lo creado en BiblioUMAG la deja vacía. El
     * ingreso a BiblioUMAG es created_at. No sirve fecha_inventario: vuelve a la de hoy
     * cada vez que una copia pasa de nuevo a «Inventario».
     */
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->date('fecha_ingreso_horizon')->nullable()->after('catalogado_por_staff_id');
        });
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->date('fecha_ingreso_horizon')->nullable()->after('fecha_inventario');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('fecha_ingreso_horizon');
        });
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropColumn('fecha_ingreso_horizon');
        });
    }
};
