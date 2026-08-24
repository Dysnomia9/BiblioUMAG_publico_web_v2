<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            // Mismo patrón que es_convenio: una tercera etiqueta para el registro
            // "Externo" (visitante que no es de convenio ni usuario interno, pero se
            // quiere distinguir en reportería/UI con su propio badge "Visita").
            $table->boolean('es_visita')->default(false)->after('es_convenio');
        });
    }

    public function down(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropColumn('es_visita');
        });
    }
};
