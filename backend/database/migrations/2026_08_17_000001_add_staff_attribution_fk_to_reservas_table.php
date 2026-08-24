<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * prestado_por/devuelto_por en reservas de sala eran strings libres que el
     * propio staff tipeaba a mano (con datalist de autocompletar) al escanear una
     * logia o confirmar llegada/devolución — no eran FK, y aunque lo fueran, el
     * dato lo seguía escribiendo el cliente. Mismo problema y misma solución que
     * ya se aplicó en prestamos (ver 2024_01_03_000004): estas columnas nuevas se
     * estampan automáticamente desde la sesión Sanctum ($request->user()->id) en
     * vez de pedirlas por request. Las columnas string viejas se mantienen como
     * snapshot legible del nombre en ese momento, ya no como fuente de verdad.
     */
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('prestado_por_staff_id')->nullable()->after('prestado_por')->constrained('staff')->nullOnDelete();
            $table->foreignId('devuelto_por_staff_id')->nullable()->after('devuelto_por')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prestado_por_staff_id');
            $table->dropConstrainedForeignId('devuelto_por_staff_id');
        });
    }
};
