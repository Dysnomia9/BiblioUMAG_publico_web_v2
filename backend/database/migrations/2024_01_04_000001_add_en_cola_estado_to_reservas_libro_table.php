<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habilita la cola de espera de reservas de libro: 'en_cola' es un
     * estado nuevo para cuando alguien reserva un libro que ya está
     * prestado/reservado por otra persona (antes esto devolvía 409 sin
     * ninguna alternativa). `fecha_retiro` pasa a ser nullable porque una
     * fila 'en_cola' todavía no tiene fecha límite de retiro — se calcula
     * recién cuando se promueve a 'pendiente' (ver ReservaLibroService).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservas_libro DROP CONSTRAINT chk_reservas_libro_estado');
        DB::statement("ALTER TABLE reservas_libro ADD CONSTRAINT chk_reservas_libro_estado CHECK (estado IN ('pendiente','cancelado','retirado','en_cola'))");

        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->date('fecha_retiro')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->date('fecha_retiro')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE reservas_libro DROP CONSTRAINT chk_reservas_libro_estado');
        DB::statement("ALTER TABLE reservas_libro ADD CONSTRAINT chk_reservas_libro_estado CHECK (estado IN ('pendiente','cancelado','retirado'))");
    }
};
