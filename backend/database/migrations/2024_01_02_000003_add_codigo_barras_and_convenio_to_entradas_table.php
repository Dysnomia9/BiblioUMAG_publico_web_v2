<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            // Código de puesto del historial importado de Horizon. Las nuevas
            // asistencias del sistema se registran sin un código ficticio.
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
