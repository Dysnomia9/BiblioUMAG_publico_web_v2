<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('prestado_por')->nullable()->after('estado');
            $table->string('devuelto_por')->nullable()->after('prestado_por');
            $table->timestamp('hora_prestamo_real')->nullable()->after('devuelto_por');
            $table->timestamp('hora_devolucion_real')->nullable()->after('hora_prestamo_real');
            $table->string('via')->default('manual')->after('hora_devolucion_real'); // manual|BC
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(['prestado_por', 'devuelto_por', 'hora_prestamo_real', 'hora_devolucion_real', 'via']);
        });
    }
};
