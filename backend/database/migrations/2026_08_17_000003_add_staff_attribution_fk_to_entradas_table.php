<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * entradas nunca tuvo ningún campo de atribución de staff. Nullable a propósito:
     * una entrada registrada por el propio usuario desde el portal de autoservicio
     * o el QR (PortalController::registrarEntrada()) no tiene staff detrás — solo se
     * estampa cuando la registra alguien de mesón (EntradaController::store/
     * storeExterno/storeConvenio/storeVisita).
     */
    public function up(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->foreignId('registrado_por_staff_id')->nullable()->after('usuario_id')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registrado_por_staff_id');
        });
    }
};
