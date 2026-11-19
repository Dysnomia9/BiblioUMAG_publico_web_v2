<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una pestaña del mesón puede admitir personas no registradas: en sus salas, además del RUT,
 * se puede reservar escribiendo el nombre de la persona a cargo (como en las salas de la
 * administración). Para las logias del hospital (HCM), que a veces piden académicos y
 * docentes que no están en la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pestanas_salas', function (Blueprint $table) {
            $table->boolean('admite_sin_registro')->default(false)->after('apartada');
        });

        DB::table('pestanas_salas')->where('nombre', 'HCM')->update(['admite_sin_registro' => true]);
    }

    public function down(): void
    {
        Schema::table('pestanas_salas', function (Blueprint $table) {
            $table->dropColumn('admite_sin_registro');
        });
    }
};
