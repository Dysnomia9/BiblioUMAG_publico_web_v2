<?php

namespace Database\Factories;

use App\Models\CategoriaRecurso;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoriaRecursoFactory extends Factory
{
    protected $model = CategoriaRecurso::class;

    public function definition(): array
    {
        $nombre = 'Recurso '.fake()->unique()->numberBetween(1, 9999);

        return [
            'clave' => str_replace(' ', '_', strtolower($nombre)),
            'nombre' => $nombre,
            'nombre_plural' => "{$nombre}s",
            'icono' => 'caja',
            'color' => 'slate',
            'limite_por_persona' => null,
            'indicaciones' => null,
            'activo' => true,
        ];
    }
}
