<?php

namespace Database\Factories;

use App\Models\Ejemplar;
use App\Models\Libro;
use Illuminate\Database\Eloquent\Factories\Factory;

class EjemplarFactory extends Factory
{
    protected $model = Ejemplar::class;

    /** Último número entregado por libro: con count(3) se arman todas antes de guardar la primera. */
    private static array $ultimos = [];

    private static function siguienteNumero(int $libroId): int
    {
        $enBase = Ejemplar::where('libro_id', $libroId)->max('numero_copia') ?? -1;

        return self::$ultimos[$libroId] = max($enBase, self::$ultimos[$libroId] ?? -1) + 1;
    }

    public function definition(): array
    {
        return [
            'libro_id' => Libro::factory(),
            // El siguiente del libro: dos copias de un título no comparten número (la primera es la 0)
            'numero_copia' => fn (array $atributos) => self::siguienteNumero($atributos['libro_id']),
            'codigo_barras' => fake()->unique()->ean13(),
            'disponible' => true,
            'estado_proceso' => 'en_estante',
        ];
    }
}
