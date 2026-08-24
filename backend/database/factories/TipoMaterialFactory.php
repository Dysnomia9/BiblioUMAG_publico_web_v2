<?php

namespace Database\Factories;

use App\Models\TipoMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class TipoMaterialFactory extends Factory
{
    protected $model = TipoMaterial::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
        ];
    }
}
