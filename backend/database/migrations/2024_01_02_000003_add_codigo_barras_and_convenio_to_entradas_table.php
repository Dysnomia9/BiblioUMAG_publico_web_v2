<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            // Toda asistencia se registra en Horizon con el código de barras del puesto de
            // trabajo — se completa siempre automáticamente en el backend (config
            // horizon_barcodes.puesto_generico), nunca se tipea a mano.
            $table->string('codigo_barras')->nullable()->after('via');
            $table->boolean('es_convenio')->default(false)->after('nombre_externo');
        });
    }

    public function down(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropColumn(['codigo_barras', 'es_convenio']);
        });
    }
};
