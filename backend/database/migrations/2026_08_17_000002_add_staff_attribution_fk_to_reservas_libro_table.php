<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * reservas_libro nunca tuvo ningún campo de atribución de staff — no había forma
     * de saber quién la tramitó cuando la creaba el staff (ReservaLibroController::store()).
     * Nullable a propósito: una reserva creada por el propio usuario desde el portal
     * de autoservicio (PortalReservaLibroController::store()) no tiene staff detrás,
     * y eso es información real, no un dato faltante.
     */
    public function up(): void
    {
        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->foreignId('registrado_por_staff_id')->nullable()->after('estado')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registrado_por_staff_id');
        });
    }
};
