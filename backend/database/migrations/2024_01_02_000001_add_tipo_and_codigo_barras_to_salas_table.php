<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->string('tipo')->default('logia')->after('piso'); // logia|puesto|sala
            $table->string('codigo_barras')->nullable()->unique()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'codigo_barras']);
        });
    }
};
