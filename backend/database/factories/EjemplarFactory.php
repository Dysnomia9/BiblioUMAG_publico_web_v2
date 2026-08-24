<?php

namespace Database\Factories;

use App\Models\Ejemplar;
use App\Models\Libro;
use Illuminate\Database\Eloquent\Factories\Factory;

class EjemplarFactory extends Factory
{
    protected $model = Ejemplar::class;

    public function definition(): array
    {
        return [
            'libro_id' => Libro::factory(),
            'numero_copia' => 1,
            'codigo_barras' => fake()->unique()->ean13(),
            'disponible' => true,
            'estado_proceso' => 'en_estante',
        ];
    }
}
