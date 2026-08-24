<?php

namespace Database\Factories;

use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservaLibroFactory extends Factory
{
    protected $model = ReservaLibro::class;

    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'libro_id' => Libro::factory(),
            'ejemplar_id' => null,
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => now()->addDays(3)->toDateString(),
            'estado' => 'pendiente',
        ];
    }
}
