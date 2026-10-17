<?php

namespace Database\Factories;

use App\Models\Multa;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class MultaFactory extends Factory
{
    protected $model = Multa::class;

    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'prestamo_id' => null,
            'tipo' => 'atraso',
            'monto' => fake()->numberBetween(1, 30) * 15,
            'estado' => 'pendiente',
            'detalle' => 'Devolución atrasada',
            'origen' => 'sistema',
        ];
    }

    public function pagada(): static
    {
        return $this->state(fn () => ['estado' => 'pagada', 'pagada_en' => now()]);
    }

    public function anulada(): static
    {
        return $this->state(fn () => ['estado' => 'anulada', 'anulada_en' => now(), 'motivo_anulacion' => 'Error de registro']);
    }
}
