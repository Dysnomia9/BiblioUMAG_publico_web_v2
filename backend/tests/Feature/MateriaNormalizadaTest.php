<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Libro;
use App\Models\Staff;
use App\Support\Materia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Las materias con sus subdivisiones escritas siempre igual, para que no se dupliquen por el formato. */
class MateriaNormalizadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_casos(): void
    {
        $casos = [
            'mapuches--historia' => 'mapuches -- historia',
            'MAPUCHES  —  HISTORIA.' => 'MAPUCHES -- HISTORIA',
            'mapuches ---  vida social' => 'mapuches -- vida social',
            '-- MAPUCHES --' => 'MAPUCHES',
            'MAPUCHES -- HISTORIA -- 1860-1970' => 'MAPUCHES -- HISTORIA -- 1860-1970',
            'ACTIVO - PASIVO' => 'ACTIVO - PASIVO',                                   // el guion simple es del nombre
            'CALCULO -- PROBLEMAS, EJERCICIOS, ETC.' => 'CALCULO -- PROBLEMAS, EJERCICIOS, ETC.',
            'Rowling, J. K.' => 'Rowling, J. K.',                                     // la inicial conserva el punto
        ];
        foreach ($casos as $entrada => $esperado) {
            $this->assertSame($esperado, Materia::normalizar($entrada), "«{$entrada}»");
        }
    }

    public function test_al_catalogar_se_ordena_y_usa_la_que_ya_existe(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $existente = Categoria::factory()->create(['nombre' => 'MAPUCHES -- HISTORIA']);
        $libro = Libro::factory()->create();

        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'categorias' => ['mapuches--historia', 'Educación---Chile.']])->assertOk();

        $this->assertSame(['Educación -- Chile', 'MAPUCHES -- HISTORIA'], $libro->fresh()->categorias->pluck('nombre')->sort()->values()->all());
        $this->assertSame(1, Categoria::where('nombre', 'ilike', 'mapuches%')->count());   // no creó otra
        $this->assertTrue($libro->fresh()->categorias->contains($existente));
    }

    public function test_las_sugerencias_entienden_el_guion_pegado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Categoria::factory()->create(['nombre' => 'MAPUCHES -- HISTORIA']);

        $nombres = collect($this->getJson('/api/categorias?q='.urlencode('mapuches--his'))->assertOk()->json())->pluck('nombre')->all();
        $this->assertContains('MAPUCHES -- HISTORIA', $nombres);
    }
}
