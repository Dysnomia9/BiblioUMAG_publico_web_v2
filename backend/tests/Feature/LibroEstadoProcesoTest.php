<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\EstadoLibroPersonalizado;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
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

    public function test_no_se_puede_dar_de_baja_un_ejemplar_apartado_para_una_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        ReservaLibro::factory()->create(['libro_id' => $ejemplar->libro_id, 'ejemplar_id' => $ejemplar->id, 'estado' => 'pendiente']);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'de_baja',
            'motivo' => 'Pérdida',
            'confirmacion' => 'CONFIRMAR',
        ]);

        $response->assertStatus(409);
        $this->assertNotEquals('de_baja', $ejemplar->refresh()->estado_proceso);
    }

    public function test_dar_de_baja_exige_escribir_confirmar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => true]);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja', 'motivo' => 'Obsoleto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmacion']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja', 'motivo' => 'Obsoleto', 'confirmacion' => 'si'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmacion']);

        $this->assertNotEquals('de_baja', $ejemplar->refresh()->estado_proceso);
    }

    public function test_una_copia_prestada_que_se_perdio_se_da_de_baja_y_cierra_el_prestamo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'tipo_item' => 'libro', 'estado' => 'activo']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'de_baja',
            'motivo' => 'El usuario la perdió',
            'confirmacion' => 'CONFIRMAR',
        ])->assertOk()->assertJsonPath('estado_proceso', 'de_baja');

        $this->assertSame('devuelto', $prestamo->refresh()->estado);
        $this->assertNotNull($prestamo->fecha_devolucion_real);
        $this->assertFalse($ejemplar->refresh()->disponible);
        $this->assertDatabaseCount('multas', 0);
        $this->assertDatabaseHas('bitacora', ['accion' => 'prestamos.cerrar_por_baja']);
        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.dar_de_baja']);
    }

    public function test_reincorporar_una_copia_perdida_la_deja_disponible_otra_vez(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'tipo_item' => 'libro', 'estado' => 'activo']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja', 'motivo' => 'Perdida', 'confirmacion' => 'CONFIRMAR'])->assertOk();
        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'inventario', 'motivo' => 'Apareció'])->assertOk();

        $ejemplar->refresh();
        $this->assertSame('inventario', $ejemplar->estado_proceso);
        $this->assertTrue($ejemplar->disponible);
    }

    public function test_dar_de_baja_un_titulo_saca_todas_sus_copias_y_cancela_la_lista_de_espera(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();
        $libre = Ejemplar::factory()->for($libro)->create(['numero_copia' => 1, 'disponible' => true]);
        $prestada = Ejemplar::factory()->for($libro)->create(['numero_copia' => 2, 'disponible' => false]);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $prestada->id, 'tipo_item' => 'libro', 'estado' => 'activo']);
        $enCola = ReservaLibro::factory()->create(['libro_id' => $libro->id, 'ejemplar_id' => null, 'estado' => 'en_cola']);

        $this->postJson("/api/libros/{$libro->id}/baja", ['motivo' => 'Edición obsoleta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmacion']);

        $this->postJson("/api/libros/{$libro->id}/baja", ['motivo' => 'Edición obsoleta', 'confirmacion' => 'CONFIRMAR'])
            ->assertOk()
            ->assertJsonPath('copias', 2)
            ->assertJsonPath('prestadas', 1)
            ->assertJsonPath('en_cola', 1);

        $this->assertSame('de_baja', $libre->refresh()->estado_proceso);
        $this->assertSame('de_baja', $prestada->refresh()->estado_proceso);
        $this->assertSame('devuelto', $prestamo->refresh()->estado);
        $this->assertSame('cancelado', $enCola->refresh()->estado);
        $this->assertDatabaseHas('ejemplar_estado_historial', ['ejemplar_id' => $libre->id, 'estado_nuevo' => 'de_baja', 'motivo' => 'Edición obsoleta']);
    }

    public function test_el_listado_de_eliminados_muestra_motivo_y_quien_dio_la_baja(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Marta Mesón']);
        Sanctum::actingAs($staff);
        $ejemplar = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Álgebra lineal']))->create(['disponible' => true]);
        $otra = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Sigue en estante']))->create(['estado_proceso' => 'en_estante']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja', 'motivo' => 'Páginas faltantes', 'confirmacion' => 'CONFIRMAR'])->assertOk();

        $respuesta = $this->getJson('/api/ejemplares/dadas-de-baja')->assertOk();
        $respuesta->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ejemplar->id)
            ->assertJsonPath('data.0.baja_motivo', 'Páginas faltantes')
            ->assertJsonPath('data.0.baja_por', 'Marta Mesón')
            ->assertJsonPath('data.0.libro.titulo', 'Álgebra lineal');

        $this->getJson('/api/ejemplares/dadas-de-baja?q=algebra')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/ejemplares/dadas-de-baja?q=zzzz')->assertOk()->assertJsonPath('total', 0);
        $this->assertNotSame($otra->id, $respuesta->json('data.0.id'));
    }

    public function test_se_puede_dar_de_baja_un_ejemplar_disponible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => true]);

        $response = $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", [
            'estado_proceso' => 'de_baja',
            'motivo' => 'Hojas sueltas, no se puede reparar',
            'confirmacion' => 'CONFIRMAR',
        ]);

        $response->assertStatus(200)->assertJsonPath('estado_proceso', 'de_baja');
        $this->assertDatabaseHas('ejemplar_estado_historial', [
            'ejemplar_id' => $ejemplar->id,
            'estado_nuevo' => 'de_baja',
            'motivo' => 'Hojas sueltas, no se puede reparar',
        ]);
    }

    public function test_dar_de_baja_sin_motivo_devuelve_422(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => true]);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo' => 'Indica el motivo de la baja']);

        $this->assertNotEquals('de_baja', $ejemplar->refresh()->estado_proceso);
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

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('total', 1);
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
