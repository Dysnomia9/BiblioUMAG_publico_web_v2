<?php

namespace Database\Factories;

use App\Models\EstadoLibroPersonalizado;
use Illuminate\Database\Eloquent\Factories\Factory;

class EstadoLibroPersonalizadoFactory extends Factory
{
    protected $model = EstadoLibroPersonalizado::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'descripcion' => null,
            'activo' => true,
        ];
    }
}
