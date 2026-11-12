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

    public function test_varias_series_y_otros_enlaces(): void
    {
        $libro = Libro::factory()->create([
            'serie' => "Notas de matemática ; 85\nNorth-Holland mathematics studies ; 67",
            'url_acceso' => 'https://drive.example/v1',
            'otros_enlaces' => "https://drive.example/v2 Vea aquí versión digital, v.2\nhttps://drive.example/v3",
        ]);
        Ejemplar::factory()->for($libro)->create();
        Sanctum::actingAs(Staff::factory()->create());

        $ficha = collect($this->getJson('/api/libros')->assertOk()->json('data.0.ficha'))->keyBy('campo');
        $this->assertSame('(Notas de matemática ; 85) (North-Holland mathematics studies ; 67)', $ficha['Serie']['valor']);
        $this->assertSame([
            ['texto' => 'Vea aquí versión digital, v.2', 'url' => 'https://drive.example/v2'],
            ['texto' => 'Abrir enlace', 'url' => 'https://drive.example/v3'],
        ], $ficha['Otros enlaces']['items']);

        // Al catalogar, cada línea tiene que empezar con una dirección web
        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'otros_enlaces' => "no es un enlace"])
            ->assertStatus(422)->assertJsonValidationErrors('otros_enlaces');
        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'otros_enlaces' => "https://x.example/a Tomo 1\nhttps://x.example/b"])->assertOk();
    }
}
