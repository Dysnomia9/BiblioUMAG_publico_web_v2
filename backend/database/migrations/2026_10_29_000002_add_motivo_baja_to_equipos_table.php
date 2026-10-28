<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un recurso de apoyo se da de baja con su motivo (se rompió, se perdió…) y la fecha, para
     * que en el inventario se vea por qué ya no se presta. Al reactivarlo se limpian; el motivo
     * de cada baja queda igual en la bitácora.
     */
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->string('motivo_baja', 500)->nullable()->after('activo');
            $table->timestamp('dado_de_baja_el')->nullable()->after('motivo_baja');
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->dropColumn(['motivo_baja', 'dado_de_baja_el']);
        });
    }
};
