<?php

namespace Database\Factories;

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
}
