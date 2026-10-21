<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multas por devolver tarde la llave de una sala: van ligadas a la reserva, igual que
     * las de libros a su préstamo. Una multa viene de un préstamo, de una reserva o de
     * ninguno (las manuales), nunca de los dos.
     */
    public function up(): void
    {
        Schema::table('multas', function (Blueprint $table) {
            $table->foreignId('reserva_id')->nullable()->after('prestamo_id')->constrained('reservas')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE multas ADD CONSTRAINT chk_multas_prestamo_o_reserva CHECK (prestamo_id IS NULL OR reserva_id IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE multas DROP CONSTRAINT chk_multas_prestamo_o_reserva');

        Schema::table('multas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserva_id');
        });
    }
};
