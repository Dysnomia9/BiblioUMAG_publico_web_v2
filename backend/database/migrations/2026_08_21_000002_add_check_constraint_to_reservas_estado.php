<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// reservas.estado nunca tuvo CHECK constraint (a diferencia de prestamos.estado,
// reservas_libro.estado, etc. — ver EnumCheckConstraintsTest) — quedó afuera del lote
// original. Se agrega ahora junto con 'cancelada' (soft delete de reservas de sala:
// cancelar ya no borra la fila, ver SalaController::destroyReserva()/
// PortalController::cancelarReservaSala()).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT chk_reservas_estado CHECK (estado IN ('activa','finalizada','no_show','cancelada'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT chk_reservas_estado');
    }
};
