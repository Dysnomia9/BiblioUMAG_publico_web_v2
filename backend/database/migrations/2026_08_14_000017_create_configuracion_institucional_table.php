<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fila única (id=1) con datos institucionales editables desde el admin — hoy solo
 * el nombre/cargo de quien firma la Constancia de No Multa, para no hardcodear ese
 * nombre en el frontend (puede cambiar la persona en el cargo). Mismo espíritu de
 * "singleton editable" que ya existe para CodigoAcceso (el QR), pero como tabla
 * propia porque el dominio es distinto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_institucional', function (Blueprint $table) {
            $table->id();
            $table->string('jefe_unidad_nombre');
            $table->string('jefe_unidad_cargo');
            $table->timestamps();
        });

        DB::table('configuracion_institucional')->insert([
            'jefe_unidad_nombre' => 'Adriana Navarro Hernández',
            'jefe_unidad_cargo' => 'Jefa de la Unidad de Gestión de Recursos Educativos',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_institucional');
    }
};
