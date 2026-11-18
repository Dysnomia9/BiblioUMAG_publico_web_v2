<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traspaso_instancia', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->uuid('uuid')->unique();
        });

        // Los IDs de una base no identifican a las filas de otra base. Esta tabla
        // permite volver a importar archivos del mismo origen, incluso de otro mes.
        Schema::create('traspaso_registros', function (Blueprint $table) {
            $table->uuid('origen');
            $table->string('tabla', 60);
            $table->bigInteger('origen_id');
            $table->bigInteger('destino_id');
            $table->primary(['origen', 'tabla', 'origen_id']);
            $table->index(['tabla', 'destino_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traspaso_registros');
        Schema::dropIfExists('traspaso_instancia');
    }
};
