<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipoEliminacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 14:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_solo_se_elimina_despues_de_la_baja_y_escribiendo_confirmar(): void
    {
        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertStatus(409);
        $this->assertNotSoftDeleted($equipo);

        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => false, 'motivo' => 'Registro ingresado por error'])->assertOk();
        foreach ([[], ['confirmacion' => 'si'], ['confirmacion' => 'eliminar']] as $datos) {
            $this->deleteJson("/api/equipos/{$equipo->id}", $datos)->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
            $this->assertNotSoftDeleted($equipo);
        }
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => ' CONFIRMAR '])->assertNoContent();
        $this->assertSoftDeleted($equipo);
        $this->assertSame(1, Equipo::withTrashed()->whereKey($equipo->id)->count());
        $registro = Bitacora::where('accion', 'equipos.eliminar')->sole();
        $this->assertSame($equipo->id, $registro->entidad_id);
        $this->assertSame($equipo->codigo_barras, $registro->datos['codigo_barras']);
    }

    public function test_desaparece_de_todos_los_filtros_y_contadores_y_no_se_puede_escanear_ni_reactivar(): void
    {
        $equipo = Equipo::factory()->create(['tipo' => 'notebook', 'activo' => false, 'codigo_barras' => '30000003222720']);
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertNoContent();

        foreach (['', '&activo=0', '&activo=1', '&tipo=notebook', '&q=03222720'] as $filtro) {
            $this->getJson('/api/equipos?page=1'.$filtro)->assertOk()->assertJsonCount(0, 'data')
                ->assertJsonPath('total', 0)->assertJsonPath('resumen', ['total' => 0, 'activos' => 0, 'bajas' => 0]);
        }
        $this->getJson('/api/equipos')->assertOk()->assertJsonCount(0);
        $categoria = collect($this->getJson('/api/categorias-recurso')->assertOk()->json())->firstWhere('clave', 'notebook');
        $this->assertSame(0, $categoria['recursos_total']);
        $this->getJson('/api/equipos/30000003222720')->assertNotFound();
        $this->getJson('/api/equipos/03222720')->assertNotFound();
        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => true])->assertNotFound();
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertNotFound();
        $this->postJson('/api/prestamos', ['usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'notebook', 'codigo_barras' => $equipo->codigo_barras])->assertNotFound();
    }

    public function test_no_se_elimina_un_recurso_no_disponible(): void
    {
        $equipo = Equipo::factory()->create(['activo' => false, 'disponible' => false]);
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertStatus(409);
        $this->assertNotSoftDeleted($equipo);
    }

    public function test_un_prestamo_sin_devolver_impide_eliminar_aunque_la_disponibilidad_este_mal(): void
    {
        $equipo = Equipo::factory()->create(['activo' => false, 'disponible' => true, 'tipo' => 'notebook']);
        Prestamo::factory()->create(['equipo_id' => $equipo->id, 'tipo_item' => 'notebook', 'estado' => 'activo', 'fecha_devolucion_real' => null]);

        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertStatus(409);
        $this->assertNotSoftDeleted($equipo);
    }

    public function test_el_historial_y_los_reportes_conservan_los_prestamos_del_recurso_eliminado(): void
    {
        $equipo = Equipo::factory()->create(['activo' => false, 'tipo' => 'notebook', 'codigo_inventario' => 'PC de prueba']);
        $prestamo = Prestamo::factory()->create(['equipo_id' => $equipo->id, 'tipo_item' => 'notebook',
            'libro_titulo' => $equipo->codigo_inventario, 'codigo_barras' => $equipo->codigo_barras,
            'estado' => 'devuelto', 'fecha_prestamo' => '2026-11-18 10:00:00', 'fecha_devolucion_real' => '2026-11-18 12:00:00']);

        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertNoContent();

        $this->assertSame($equipo->id, $prestamo->fresh()->equipo->id);
        $this->getJson('/api/prestamos?vista=devueltos')->assertOk()
            ->assertJsonPath('data.0.equipo_id', $equipo->id)->assertJsonPath('data.0.libro_titulo', 'PC de prueba');
        $this->getJson('/api/reportes/resumen?tab=libros&periodo=mes&fecha=2026-11-18')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('porEquipo', [['label' => 'PC de prueba', 'value' => 1]]);
    }

    public function test_eliminar_requiere_el_permiso_de_administrar_recursos(): void
    {
        $equipo = Equipo::factory()->create(['activo' => false]);
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->deleteJson("/api/equipos/{$equipo->id}", ['confirmacion' => 'confirmar'])->assertForbidden();
        $this->assertNotSoftDeleted($equipo);
    }
}
