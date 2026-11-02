<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salas que se reservan a nombre de una sola persona: el docente o funcionario que la pide
 * para su curso o actividad, sin anotar a cada asistente (suelen ser muchos). Son los
 * seminarios: Seminario 1 (SEM) y, si se crea, Seminario 2 (SEM2). Las demás salas siguen
 * pidiendo el grupo completo (config/salas.php, personas_minimas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->boolean('solo_responsable')->default(false);
        });

        DB::table('salas')->whereIn('codigo', ['SEM', 'SEM2'])->update(['solo_responsable' => true]);
    }

    public function down(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->dropColumn('solo_responsable');
        });
    }
};
