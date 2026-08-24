<?php

namespace Database\Factories;

use App\Models\Entrada;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntradaFactory extends Factory
{
    protected $model = Entrada::class;

    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'rut_externo' => null,
            'nombre_externo' => null,
            'fecha_hora_entrada' => now(),
            'fecha_hora_salida' => null,
            'via' => 'manual',
            'codigo_barras' => null,
            'es_convenio' => false,
            'es_visita' => false,
        ];
    }
}
