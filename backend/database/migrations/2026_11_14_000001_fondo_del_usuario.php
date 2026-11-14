<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los usuarios del portal también eligen el fondo en modo día (menú de la cuenta › Aspecto
 * visual), igual que el personal. Vacío es el del portal (Neblina). Las claves válidas son
 * las de Staff::FONDOS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('fondo', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('fondo');
        });
    }
};
