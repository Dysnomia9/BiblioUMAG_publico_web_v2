<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las reservas de sala son para 3 personas o más (config/salas.php, personas_minimas): las
 * logias que decían capacidad 2 pasan a 3, para que no muestren menos que el mínimo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salas')->where('capacidad', '<', 3)->update(['capacidad' => 3, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sin vuelta atrás: no queda registro de cuáles tenían 2.
    }
};
