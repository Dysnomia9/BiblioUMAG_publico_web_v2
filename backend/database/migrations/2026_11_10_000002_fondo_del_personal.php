<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada persona del personal elige el fondo del panel en modo día (menú de la cuenta ›
 * Aspecto visual), y queda en su cuenta para que la siga en cualquier computador. Vacío es
 * el predeterminado. Las claves válidas están en Staff::FONDOS; no hay restricción en la
 * base porque la lista crece con los diseños del frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('fondo', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('fondo');
        });
    }
};
