<?php

namespace Database\Factories;

use App\Models\Sala;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalaFactory extends Factory
{
    protected $model = Sala::class;

    public function definition(): array
    {
        return [
            'nombre' => 'Logia '.fake()->unique()->numberBetween(1, 999),
            'capacidad' => fake()->numberBetween(2, 6),
            'tipo' => 'logia',
            'codigo_barras' => null,
        ];
    }
}
