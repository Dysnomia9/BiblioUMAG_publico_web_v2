<?php

namespace Database\Factories;

use App\Models\Sala;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalaFactory extends Factory
{
    protected $model = Sala::class;

    public function definition(): array
    {
        $numero = fake()->unique()->numberBetween(100, 999);

        // Números de tres cifras: no chocan con las 15 logias que crea la migración.
        return [
            'nombre' => "Logia {$numero}",
            'codigo' => "L{$numero}",
            'capacidad' => fake()->numberBetween(2, 6),
            'tipo' => 'logia',
        ];
    }
}
