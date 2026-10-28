<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Fasciculo;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fascículos de las revistas: qué números llegaron y cuáles faltan. */
class FasciculoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function revista(): Libro
    {
        return Libro::factory()->create([
            'titulo' => 'Revista Médica',
            'tipo_material_id' => TipoMaterial::factory()->create(['plantilla' => 'publicacion_seriada'])->id,
        ]);
    }

    public function test_lista_los_fasciculos_de_una_revista_con_su_estado_y_los_conteos(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $revista = $this->revista();
        Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'Vol. 84, n.º 4', 'cronologia' => 'abril 2019', 'fecha_publicacion' => '2019-04-01', 'estado' => 'recibido', 'fecha_recepcion' => '2019-04-20']);
        Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'Vol. 84, n.º 5', 'cronologia' => 'mayo 2019', 'fecha_publicacion' => '2019-05-01', 'estado' => 'faltante']);

        $this->getJson("/api/libros/{$revista->id}/fasciculos")->assertOk()
            ->assertJsonPath('fasciculos.0.enumeracion', 'Vol. 84, n.º 5')
            ->assertJsonPath('fasciculos.0.estado', 'faltante')
            ->assertJsonPath('fasciculos.1.fecha_recepcion', '2019-04-20')
            ->assertJsonPath('conteos.recibido', 1)
            ->assertJsonPath('conteos.faltante', 1)
            ->assertJsonPath('conteos.esperado', 0);
    }

    public function test_se_registra_la_recepcion_de_un_fasciculo_esperado(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $revista = $this->revista();
        $fasciculo = Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'Vol. 1, n.º 2', 'fecha_esperada' => '2026-07-01', 'estado' => 'reclamado']);

        $this->patchJson("/api/fasciculos/{$fasciculo->id}/recibir")->assertOk()
            ->assertJsonPath('estado', 'recibido')
            ->assertJsonPath('fecha_recepcion', '2026-07-08');

        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.recibir_fasciculo', 'entidad_id' => $fasciculo->id]);

        $otro = Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'Vol. 1, n.º 3', 'estado' => 'esperado']);
        $this->patchJson("/api/fasciculos/{$otro->id}/recibir", ['fecha_recepcion' => '2026-07-20'])->assertStatus(422)
            ->assertJsonPath('errors.fecha_recepcion.0', 'La fecha de recepción no puede ser futura.');
        $this->patchJson("/api/fasciculos/{$fasciculo->id}/recibir")->assertStatus(409);
    }

    public function test_se_agrega_un_fasciculo_y_su_copia_tiene_que_ser_de_la_misma_revista(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $revista = $this->revista();
        $copiaPropia = Ejemplar::factory()->for($revista)->create();
        $copiaAjena = Ejemplar::factory()->create();

        $this->postJson("/api/libros/{$revista->id}/fasciculos", [
            'enumeracion' => 'Vol. 2, n.º 1',
            'ejemplar_id' => $copiaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('ejemplar_id');

        $this->postJson("/api/libros/{$revista->id}/fasciculos", [
            'enumeracion' => 'Vol. 2, n.º 1',
            'cronologia' => 'enero 2026',
            'ejemplar_id' => $copiaPropia->id,
            'estado' => 'recibido',
        ])->assertCreated()
            ->assertJsonPath('ejemplar.id', $copiaPropia->id)
            ->assertJsonPath('fecha_recepcion', now()->toDateString());
    }

    public function test_un_libro_que_no_es_revista_no_tiene_fasciculos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();

        $this->postJson("/api/libros/{$libro->id}/fasciculos", ['enumeracion' => 'n.º 1'])->assertStatus(422);
        $this->assertDatabaseCount('fasciculos', 0);
    }

    public function test_registrar_fasciculos_exige_el_permiso_de_catalogar(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $revista = $this->revista();
        $fasciculo = Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'n.º 1']);

        $this->postJson("/api/libros/{$revista->id}/fasciculos", ['enumeracion' => 'n.º 2'])->assertForbidden();
        $this->patchJson("/api/fasciculos/{$fasciculo->id}/recibir")->assertForbidden();
    }

    public function test_la_base_valida_el_estado_y_la_referencia_externa_unica(): void
    {
        $revista = $this->revista();
        Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'n.º 1', 'referencia_externa' => 'horizon:serial:1']);

        $this->expectException(QueryException::class);
        Fasciculo::create(['libro_id' => $revista->id, 'enumeracion' => 'n.º 2', 'referencia_externa' => 'horizon:serial:1']);
    }

    public function test_la_base_rechaza_un_estado_desconocido(): void
    {
        $this->expectException(QueryException::class);
        Fasciculo::create(['libro_id' => $this->revista()->id, 'enumeracion' => 'n.º 1', 'estado' => 'perdido']);
    }
}
