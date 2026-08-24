<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres, a diferencia de MySQL/InnoDB, no indexa columnas FK
     * automáticamente — estas 5 quedaron sin índice propio desde sus
     * migraciones originales pese a consultarse/filtrarse seguido.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->index('libro_id');
            $table->index('equipo_id');
            $table->index('fecha_prestamo');
            $table->index('fecha_devolucion');
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->index('libro_id');
        });

        Schema::table('codigo_acceso', function (Blueprint $table) {
            $table->index('generado_por');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropIndex(['libro_id']);
            $table->dropIndex(['equipo_id']);
            $table->dropIndex(['fecha_prestamo']);
            $table->dropIndex(['fecha_devolucion']);
        });

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropIndex(['libro_id']);
        });

        Schema::table('codigo_acceso', function (Blueprint $table) {
            $table->dropIndex(['generado_por']);
        });
    }
};
