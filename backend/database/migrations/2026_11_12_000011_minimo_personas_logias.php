<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mínimo de personas para las logias (Administración › Salas): un interruptor y el número.
 * Encendido, ese mínimo vale para todas las logias en el mesón y en el portal; apagado, se
 * usan los de config/salas.php (3 en el portal, 1 en el mesón).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucional', function (Blueprint $table) {
            $table->boolean('minimo_personas_logias_activo')->default(false);
            $table->unsignedSmallInteger('minimo_personas_logias')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucional', function (Blueprint $table) {
            $table->dropColumn(['minimo_personas_logias_activo', 'minimo_personas_logias']);
        });
    }
};
