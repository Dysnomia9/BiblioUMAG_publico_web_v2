<?php

namespace Database\Factories;

use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrestamoFactory extends Factory
{
    protected $model = Prestamo::class;

    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'ejemplar_id' => null,
            'equipo_id' => null,
            'libro_titulo' => fake()->sentence(3),
            'tipo_item' => 'libro',
            'codigo_barras' => null,
            'fecha_prestamo' => now(),
            'fecha_devolucion' => now()->addDays(7),
            'fecha_devolucion_real' => null,
            'estado' => 'activo',
            'prestado_por' => null,
            'devuelto_por' => null,
            'multa_monto' => null,
            'multa_estado' => null,
            'multa_pagada_en' => null,
            'multa_pagada_por' => null,
        ];
    }
}
