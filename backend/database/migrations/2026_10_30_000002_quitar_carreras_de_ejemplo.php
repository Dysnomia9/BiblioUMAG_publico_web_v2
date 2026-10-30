<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las carreras que sembraron create_carreras_table y seed_estados_personalizados eran de
 * ejemplo: se ofrecían al catalogar y en el formulario de estudiantes aunque nadie las
 * tuviera. Al catalogar ahora se sugieren las carreras de los usuarios (ver
 * CatalogoLibroController::carreras), y la que se asigna a un libro queda en el catálogo.
 * Se quitan solo las que ningún libro tiene.
 */
return new class extends Migration
{
    private array $ejemplos = [
        'Ingeniería Civil Informática',
        'Ingeniería Comercial',
        'Derecho',
        'Enfermería',
        'Trabajo Social',
        'Pedagogía en Educación Básica',
        'Medicina Veterinaria',
        'Construcción Civil',
        'General / Otros',
    ];

    public function up(): void
    {
        DB::table('carreras')
            ->whereIn('nombre', $this->ejemplos)
            ->whereNotExists(fn ($q) => $q->from('libro_carrera')->whereColumn('libro_carrera.carrera_id', 'carreras.id'))
            ->delete();
    }

    public function down(): void
    {
        $ahora = now();
        DB::table('carreras')->insertOrIgnore(
            collect($this->ejemplos)->map(fn ($nombre) => ['nombre' => $nombre, 'created_at' => $ahora, 'updated_at' => $ahora])->all()
        );
    }
};
