<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Ubicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EjemplarCambioMasivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_sin_ningun_filtro_devuelve_422(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->postJson('/api/ejemplares/cambio-masivo/preview', []);

        $response->assertStatus(422);
    }

    public function test_preview_filtra_por_ubicacion_sin_escribir_nada(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $central = Ubicacion::firstOrCreate(['nombre' => 'Biblioteca Central']);
        $otraSede = Ubicacion::factory()->create(['nombre' => 'Otra Sede']);
        Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $central->id, 'estado_proceso' => 'en_estante']);
        Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $central->id, 'estado_proceso' => 'en_estante']);
        Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $otraSede->id, 'estado_proceso' => 'en_estante']);

        $response = $this->postJson('/api/ejemplares/cambio-masivo/preview', ['ubicacion_id' => $central->id]);

        $response->assertStatus(200)->assertJsonPath('total', 2);
        // El preview no debe escribir nada — los 3 ejemplares siguen exactamente igual.
        $this->assertSame(3, Ejemplar::where('estado_proceso', 'en_estante')->count());
    }

    public function test_ejecutar_cambia_solo_los_ejemplares_que_matchean_el_filtro(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $central = Ubicacion::firstOrCreate(['nombre' => 'Biblioteca Central']);
        $otraSede = Ubicacion::factory()->create(['nombre' => 'Otra Sede']);
        $enCentral1 = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $central->id, 'estado_proceso' => 'en_estante']);
        $enCentral2 = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $central->id, 'estado_proceso' => 'en_estante']);
        $enOtraSede = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $otraSede->id, 'estado_proceso' => 'en_estante']);

        $response = $this->postJson('/api/ejemplares/cambio-masivo/ejecutar', [
            'ubicacion_id' => $central->id,
            'nuevo_estado' => 'inventario',
        ]);

        $response->assertStatus(200)->assertJsonPath('total_actualizados', 2);

        $this->assertDatabaseHas('ejemplares', ['id' => $enCentral1->id, 'estado_proceso' => 'inventario']);
        $this->assertDatabaseHas('ejemplares', ['id' => $enCentral2->id, 'estado_proceso' => 'inventario']);
        $this->assertDatabaseHas('ejemplares', ['id' => $enOtraSede->id, 'estado_proceso' => 'en_estante']);
    }

    public function test_ejecutar_escribe_historial_con_el_mismo_lote_id_para_todo_el_grupo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $bodega = Ubicacion::factory()->create(['nombre' => 'Bodega']);
        Ejemplar::factory()->for(Libro::factory())->count(3)->create(['ubicacion_id' => $bodega->id, 'estado_proceso' => 'en_estante']);

        $response = $this->postJson('/api/ejemplares/cambio-masivo/ejecutar', [
            'ubicacion_id' => $bodega->id,
            'nuevo_estado' => 'estanteria_auxiliar',
            'motivo' => 'Inventario anual',
        ]);

        $loteId = $response->json('lote_id');
        $this->assertNotNull($loteId);
        $this->assertSame(3, \App\Models\EjemplarEstadoHistorial::where('lote_id', $loteId)->count());
        $this->assertDatabaseHas('ejemplar_estado_historial', [
            'lote_id' => $loteId,
            'estado_anterior' => 'en_estante',
            'estado_nuevo' => 'estanteria_auxiliar',
            'motivo' => 'Inventario anual',
        ]);
    }

    public function test_ejecutar_excluye_de_baja_a_ejemplares_actualmente_prestados(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $bodega = Ubicacion::factory()->create(['nombre' => 'Bodega']);
        $disponible = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $bodega->id, 'disponible' => true]);
        $prestado = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $bodega->id, 'disponible' => false]);

        $response = $this->postJson('/api/ejemplares/cambio-masivo/ejecutar', [
            'ubicacion_id' => $bodega->id,
            'nuevo_estado' => 'de_baja',
        ]);

        $response->assertStatus(200)->assertJsonPath('total_actualizados', 1);
        $this->assertDatabaseHas('ejemplares', ['id' => $disponible->id, 'estado_proceso' => 'de_baja']);
        $this->assertDatabaseMissing('ejemplares', ['id' => $prestado->id, 'estado_proceso' => 'de_baja']);
    }

    public function test_staff_no_admin_no_puede_ejecutar_cambio_masivo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $bodega = Ubicacion::factory()->create(['nombre' => 'Bodega']);

        $response = $this->postJson('/api/ejemplares/cambio-masivo/ejecutar', [
            'ubicacion_id' => $bodega->id,
            'nuevo_estado' => 'inventario',
        ]);

        $response->assertStatus(403);
    }

    public function test_historial_estado_lista_los_cambios_de_un_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'inventario']);

        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'en_estante'])->assertStatus(200);

        $response = $this->getJson("/api/ejemplares/historial-estado?ejemplar_id={$ejemplar->id}");

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('en_estante', $response->json('0.estado_nuevo'));
    }
}
