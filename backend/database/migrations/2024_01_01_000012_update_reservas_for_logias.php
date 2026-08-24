<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('nombre_usuario');
            $table->unsignedTinyInteger('cantidad_personas')->default(1)->after('rut_usuario');
            $table->json('ruts')->nullable()->after('cantidad_personas');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(['cantidad_personas', 'ruts']);
            $table->string('nombre_usuario')->default('');
        });
    }
};
