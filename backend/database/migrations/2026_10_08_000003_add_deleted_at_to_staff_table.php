<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas del personal eliminadas (Personal y Roles → Eliminar): la fila se conserva
 * con deleted_at, porque la bitácora, los préstamos, las multas, los cambios de estado
 * y demás historial apuntan a ella y deben seguir diciendo quién hizo cada cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
