<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salas que no se prestan a estudiantes: no aparecen en el portal y en el mesón solo las
 * reserva quien tiene el permiso salas.administracion (el Administrador). La Sala de
 * Seminarios es la primera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->boolean('solo_administracion')->default(false);
        });

        DB::table('salas')->where('codigo', 'SEM')->update(['solo_administracion' => true]);
    }

    public function down(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->dropColumn('solo_administracion');
        });
    }
};
