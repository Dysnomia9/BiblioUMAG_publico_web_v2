<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('carrera')->nullable()->after('tipo');
            $table->unsignedSmallInteger('anio_ingreso')->nullable()->after('carrera');
            $table->string('sexo')->nullable()->after('anio_ingreso');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['carrera', 'anio_ingreso', 'sexo']);
        });
    }
};
