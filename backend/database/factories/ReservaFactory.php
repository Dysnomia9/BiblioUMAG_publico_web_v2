<?php

namespace Database\Factories;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservaFactory extends Factory
{
    protected $model = Reserva::class;

    public function definition(): array
    {
        return [
            'sala_id' => Sala::factory(),
            'usuario_id' => Usuario::factory(),
            'rut_usuario' => Rut::formatear(fake()->unique()->numberBetween(1000000, 25000000)),
            'cantidad_personas' => 2,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'estado' => 'activa',
        ];
    }

    public function conParticipantes($usuarios): static
    {
        return $this->afterCreating(fn (Reserva $reserva) => $reserva->participantes()->sync(collect($usuarios)->pluck('id')));
    }
}
