<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\UsoSinRut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** «Devolución sin RUT»: libros usados y dejados en la mesa, pistoleados en el mesón. */
class UsoSinRutTest extends TestCase
{
    use RefreshDatabase;

    private function ejemplar(string $titulo = 'Cálculo'): Ejemplar
    {
        return Ejemplar::factory()->for(Libro::factory()->create(['titulo' => $titulo]))->create();
    }

    public function test_el_meson_registra_el_uso_y_queda_en_la_lista_de_hoy(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create(['nombre' => 'Mesón Tarde']));
        $ejemplar = $this->ejemplar();

        $this->postJson('/api/usos-sin-rut', ['codigo' => $ejemplar->codigo_barras])
            ->assertCreated()
            ->assertJsonPath('ejemplar_id', $ejemplar->id)
            ->assertJsonPath('registrado_por', 'Mesón Tarde');

        $this->getJson('/api/usos-sin-rut/hoy')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'recientes');
        $this->assertDatabaseHas('bitacora', ['accion' => 'prestamos.sin_rut', 'actor_nombre' => 'Mesón Tarde']);
        // No es un préstamo: el libro sigue libre.
        $this->assertDatabaseCount('prestamos', 0);
    }

    public function test_la_lista_de_hoy_trae_cinco_y_mas_si_se_pide(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $ejemplar = $this->ejemplar();
        foreach (range(1, 7) as $_) {
            UsoSinRut::create(['ejemplar_id' => $ejemplar->id, 'libro_titulo' => 'Cálculo', 'fecha_hora' => now()]);
        }

        $this->getJson('/api/usos-sin-rut/hoy')->assertJsonPath('total', 7)->assertJsonCount(5, 'recientes');
        $this->getJson('/api/usos-sin-rut/hoy?limite=25')->assertJsonCount(7, 'recientes');
    }

    public function test_un_libro_prestado_no_se_registra_sin_rut(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $ejemplar = $this->ejemplar();
        Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'tipo_item' => 'libro', 'estado' => 'activo']);

        $this->postJson('/api/usos-sin-rut', ['codigo' => $ejemplar->codigo_barras])->assertStatus(409);
        $this->postJson('/api/usos-sin-rut', ['codigo' => '99999999999999'])->assertNotFound();
        $this->assertDatabaseCount('usos_sin_rut', 0);
    }

    public function test_solo_quien_lo_registro_lo_deshace(): void
    {
        $yo = Staff::factory()->asistente()->create();
        Sanctum::actingAs($yo);
        $id = $this->postJson('/api/usos-sin-rut', ['codigo' => $this->ejemplar()->codigo_barras])->json('id');

        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->deleteJson("/api/usos-sin-rut/{$id}")->assertForbidden();

        Sanctum::actingAs($yo);
        $this->deleteJson("/api/usos-sin-rut/{$id}")->assertNoContent();
        $this->assertDatabaseCount('usos_sin_rut', 0);
    }

    public function test_reportes_los_cuentan_como_sin_rut(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->ejemplar('Álgebra');
        Prestamo::factory()->create(['tipo_item' => 'libro', 'libro_titulo' => 'Cálculo']);
        foreach (range(1, 2) as $_) {
            UsoSinRut::create(['ejemplar_id' => $ejemplar->id, 'libro_titulo' => 'Álgebra', 'fecha_hora' => now()]);
        }

        $this->getJson('/api/reportes/resumen?tab=prestamos')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('usosSinRut', 2)
            ->assertJsonPath('porTipoPrestamo', [['label' => 'sin_rut', 'value' => 2], ['label' => 'bibliografico', 'value' => 1]])
            ->assertJsonPath('porLibro.0', ['label' => 'Álgebra', 'value' => 2]);

        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_prestamo=sin_rut')->assertJsonPath('total', 2);
        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_prestamo=bibliografico')->assertJsonPath('total', 1);
        // Sin persona: con un filtro de quién no cuentan.
        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_usuario=estudiante')->assertJsonPath('usosSinRut', 0);
        $this->getJson('/api/reportes/resumen?tab=libros')->assertJsonPath('total', 3);
    }
}
