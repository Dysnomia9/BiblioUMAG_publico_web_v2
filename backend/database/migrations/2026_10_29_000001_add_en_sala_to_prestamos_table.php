<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Préstamo para uso en sala: la persona lee el material en la biblioteca y lo devuelve
     * el mismo día. Sirve también para lo que no se presta a domicilio (referencia) y no
     * cuenta para el máximo de libros a domicilio.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->boolean('en_sala')->default(false)->after('tipo_item');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn('en_sala');
        });
    }
};
