<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * Por defecto 'bibliotecario': cubre el trabajo operativo completo, así los
     * tests que no son sobre permisos no dependen de cuál es el rol más bajo.
     * Los tests de restricciones piden el rol explícitamente (asistente/admin).
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'nombre' => fake()->name(),
            'rol' => 'bibliotecario',
            'activo' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['rol' => 'admin']);
    }

    public function asistente(): static
    {
        return $this->state(fn () => ['rol' => 'asistente']);
    }

    /**
     * Reserva salas, pero sin «Reservas largas» (salas.sin_limite): para probar el máximo de
     * 2 horas, ahora que todos los roles de siempre tienen ese permiso.
     */
    public function sinReservasLargas(): static
    {
        return $this->state(function () {
            Rol::firstOrCreate(
                ['clave' => 'reservas_cortas'],
                ['nombre' => 'Reservas cortas', 'nivel' => 5, 'permisos' => ['salas.gestionar']],
            );

            return ['rol' => 'reservas_cortas'];
        });
    }
}
