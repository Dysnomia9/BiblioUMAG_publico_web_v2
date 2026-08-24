<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoLibroTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_autores_ordenados_por_nombre(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Autor::factory()->create(['nombre' => 'Zorro']);
        Autor::factory()->create(['nombre' => 'Alfa']);

        $response = $this->getJson('/api/autores');

        $response->assertStatus(200);
        $this->assertSame(['Alfa', 'Zorro'], collect($response->json())->pluck('nombre')->all());
    }

    public function test_lista_categorias(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Categoria::factory()->create(['nombre' => 'Historia']);

        $response = $this->getJson('/api/categorias');

        $response->assertStatus(200)->assertJsonFragment(['nombre' => 'Historia']);
    }

    public function test_lista_carreras_incluye_las_sembradas_por_la_migracion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->getJson('/api/carreras');

        $response->assertStatus(200)->assertJsonFragment(['nombre' => 'Derecho']);
    }
}
