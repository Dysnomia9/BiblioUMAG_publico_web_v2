<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** El « ; » de ISBD no parte el valor en etiquetas: solo se separan las listas. */
class FichaPuntuacionIsbdTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_serie_y_la_descripcion_van_enteras_y_las_listas_separadas(): void
    {
        $libro = Libro::factory()->create([
            'serie' => 'Aporte al debate laboral ; 34',
            'descripcion_fisica' => '86 p. : il. ; 24 cm.',
            'materias_geograficas' => "Magallanes (Chile)\nPatagonia",
        ]);
        Ejemplar::factory()->for($libro)->create();
        Sanctum::actingAs(Staff::factory()->create());

        $ficha = collect($this->getJson('/api/libros')->assertOk()->json('data.0.ficha'))->keyBy('campo');

        $this->assertSame('(Aporte al debate laboral ; 34)', $ficha['Serie']['valor']);
        $this->assertNull($ficha['Serie']['items']);
        $this->assertNull($ficha['Descripción física']['items']);
        $this->assertSame(['Magallanes (Chile)', 'Patagonia'], array_column($ficha['Materia (nombre geográfico)']['items'], 'texto'));
    }
}
