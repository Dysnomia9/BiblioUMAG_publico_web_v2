<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\EstadoLibroPersonalizado;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibroEstadoProcesoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambiar_estado_actualiza_el_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'inventario']);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'en_estante',
        ]);

        $response->assertStatus(200)->assertJsonPath('estado_proceso', 'en_estante');
    }

    public function test_cambiar_estado_a_inventario_estampa_fecha_inventario(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'en_estante', 'fecha_inventario' => null]);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'inventario',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($ejemplar->refresh()->fecha_inventario);
    }

    public function test_no_se_puede_dar_de_baja_un_ejemplar_prestado_o_reservado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'de_baja',
        ]);

        $response->assertStatus(409);
        $this->assertNotEquals('de_baja', $ejemplar->refresh()->estado_proceso);
    }

    public function test_se_puede_dar_de_baja_un_ejemplar_disponible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => true]);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'de_baja',
        ]);

        $response->assertStatus(200)->assertJsonPath('estado_proceso', 'de_baja');
    }

    public function test_cambiar_estado_con_valor_invalido_devuelve_422(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'no_existe',
        ]);

        $response->assertStatus(422);
    }

    public function test_cambiar_estado_a_coleccion_movil(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'en_estante']);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'coleccion_movil',
        ]);

        $response->assertStatus(200)->assertJsonPath('estado_proceso', 'coleccion_movil');
    }

    public function test_cambiar_estado_a_personalizado_sin_estado_personalizado_id_devuelve_422(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'personalizado',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('estado_personalizado_id');
    }

    public function test_cambiar_estado_a_personalizado_con_id_valido(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $estadoPersonalizado = EstadoLibroPersonalizado::factory()->create(['nombre' => 'Restauración']);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'personalizado',
            'estado_personalizado_id' => $estadoPersonalizado->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('estado_proceso', 'personalizado')
            ->assertJsonPath('estado_personalizado_id', $estadoPersonalizado->id);
    }

    public function test_cambiar_estado_escribe_historial(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'inventario']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'en_estante'])
            ->assertStatus(200);

        $this->assertDatabaseHas('ejemplar_estado_historial', [
            'ejemplar_id' => $ejemplar->id,
            'estado_anterior' => 'inventario',
            'estado_nuevo' => 'en_estante',
        ]);
    }

    public function test_index_busca_por_titulo_autor_o_codigo_de_barras(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Cien Años de Soledad']);
        $libro->autores()->attach(\App\Models\Autor::factory()->create(['nombre' => 'García Márquez']));
        Ejemplar::factory()->for($libro)->create();

        Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Otro Libro']))->create();

        $response = $this->getJson('/api/libros?q=Soledad');

        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_buscar_por_codigo_no_encontrado_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->getJson('/api/ejemplares/NO-EXISTE');

        $response->assertStatus(404);
    }

    public function test_buscar_por_codigo_encontrado_devuelve_el_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->getJson("/api/ejemplares/{$ejemplar->codigo_barras}");

        $response->assertStatus(200)->assertJsonPath('id', $ejemplar->id);
    }
}
