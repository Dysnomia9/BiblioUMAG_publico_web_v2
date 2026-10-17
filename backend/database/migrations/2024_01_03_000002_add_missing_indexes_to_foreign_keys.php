<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices de columnas que se consultan/filtran seguido. En las que son FK,
     * InnoDB ya tenía un índice propio (con el nombre de la FK): al crear este,
     * MySQL descarta el suyo y la FK pasa a usar este.
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
        // Las FK necesitan un índice: en vez de borrarlo, vuelve a tener el nombre que
        // InnoDB le pone al que crea solo para la FK (el estado de antes de esta migración).
        $devolverAFk = fn (string $tabla, string $columna) => DB::statement(
            "ALTER TABLE {$tabla} RENAME INDEX {$tabla}_{$columna}_index TO {$tabla}_{$columna}_foreign"
        );

        $devolverAFk('prestamos', 'libro_id');
        $devolverAFk('prestamos', 'equipo_id');
        $devolverAFk('reservas_libro', 'libro_id');
        $devolverAFk('codigo_acceso', 'generado_por');

        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropIndex(['fecha_prestamo']);
            $table->dropIndex(['fecha_devolucion']);
        });
    }
};
