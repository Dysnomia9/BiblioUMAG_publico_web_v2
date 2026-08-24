<?php

namespace Database\Factories;

use App\Models\Usuario;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'rut' => Rut::formatear(fake()->unique()->numberBetween(1000000, 25000000)),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'tipo' => 'estudiante',
            'carrera' => fake()->randomElement([
                'Ingeniería Civil Informática',
                'Derecho',
                'Enfermería',
                'Medicina Veterinaria',
                'Construcción Civil',
            ]),
            'anio_ingreso' => fake()->numberBetween(2018, 2025),
            'sexo' => fake()->randomElement(['M', 'F']),
            'activo' => true,
            'qr_code' => null,
        ];
    }
}
