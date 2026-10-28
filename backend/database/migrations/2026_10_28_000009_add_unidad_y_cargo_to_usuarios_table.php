<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los docentes y funcionarios no tienen carrera: se los ubica por la unidad o departamento
     * donde trabajan y su cargo. Son opcionales y se pueden traer en la carga masiva.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('unidad', 255)->nullable()->after('sexo');
            $table->string('cargo', 255)->nullable()->after('unidad');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['unidad', 'cargo']);
        });
    }
};
