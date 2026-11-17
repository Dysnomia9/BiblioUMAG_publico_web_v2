<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('nombre_actividad', 120)->nullable()->after('rut_usuario');
            $table->string('rut_usuario')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('nombre_actividad');
            $table->string('rut_usuario')->nullable(false)->change();
        });
    }
};
