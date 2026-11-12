<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Materia escrita en el Listado: trae todas las que tienen esas palabras, con o sin subdivisiones. */
class FiltroMateriaEscritaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapuches_trae_todas_sus_subdivisiones(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libros = [];
        foreach (['MAPUCHES', 'MAPUCHES -- HISTORIA -- 1860-1970', 'MAPUCHES -- VIDA SOCIAL Y COSTUMBRES', 'EDUCACION -- CHILE'] as $i => $materia) {
            $libros[$i] = Libro::factory()->create(['titulo' => "Libro {$i}"]);
            $libros[$i]->categorias()->attach(Categoria::factory()->create(['nombre' => $materia]));
        }

        $ids = fn (string $q) => collect($this->getJson('/api/libros?materia='.urlencode($q))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$libros[0]->id, $libros[1]->id, $libros[2]->id], $ids('mapuches'));
        $this->assertSame([$libros[1]->id], $ids('mapuches historia'));   // las dos palabras, en la misma materia
        $this->assertSame([$libros[3]->id], $ids('educación'));           // sin importar tildes
        $this->assertSame([], $ids('educacion mapuches'));                 // no mezcla materias distintas
    }
}
