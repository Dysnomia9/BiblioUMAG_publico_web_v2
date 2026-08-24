<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de carreras para el multi-select de "carrera(s) asignadas" de un
 * libro (LibroController). Independiente de usuarios.carrera, que sigue
 * siendo texto libre — no se toca ese campo aquí.
 */
return new class extends Migration
{
    private array $carreras = [
        'Ingeniería Civil Informática',
        'Ingeniería Comercial',
        'Derecho',
        'Enfermería',
        'Trabajo Social',
        'Pedagogía en Educación Básica',
        'Medicina Veterinaria',
        'Construcción Civil',
    ];

    public function up(): void
    {
        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        $ahora = now();
        DB::table('carreras')->insert(
            collect($this->carreras)->map(fn ($nombre) => [
                'nombre' => $nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('carreras');
    }
};
