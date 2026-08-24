<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->foreignId('usuario_id')->nullable()->change();
            $table->string('rut_externo')->nullable()->after('usuario_id');
            $table->string('nombre_externo')->nullable()->after('rut_externo');
        });
    }

    public function down(): void
    {
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropColumn(['rut_externo', 'nombre_externo']);
            $table->foreignId('usuario_id')->nullable(false)->change();
        });
    }
};
