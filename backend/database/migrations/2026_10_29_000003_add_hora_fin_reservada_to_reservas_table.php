<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuando un grupo devuelve la llave antes de tiempo y el mesón libera el resto del bloque,
     * la reserva pasa a terminar a la hora en que volvió la llave (así la sala queda libre para
     * otro grupo) y aquí se guarda hasta qué hora estaba reservada.
     */
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->time('hora_fin_reservada')->nullable()->after('hora_fin');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('hora_fin_reservada');
        });
    }
};
