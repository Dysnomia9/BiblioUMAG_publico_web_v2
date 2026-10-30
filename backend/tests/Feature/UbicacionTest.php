<?php

namespace Tests\Feature;

use App\Models\DiaSinAtencion;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Ubicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Ubicaciones (Administración → Catálogos): crear, renombrar y eliminar sin dejar copias sin ubicación. */
class UbicacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_le_cambia_el_nombre_y_sus_copias_la_siguen_teniendo(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ubicacion = Ubicacion::create(['nombre' => 'Inst. Patagonia']);
        $copia = Ejemplar::factory()->for(Libro::factory())->create(['ubicacion_id' => $ubicacion->id]);

        $this->patchJson("/api/ubicaciones/{$ubicacion->id}", ['nombre' => 'Instituto de la Patagonia'])
            ->assertOk()
            ->assertJsonPath('nombre', 'Instituto de la Patagonia');

        $this->assertSame($ubicacion->id, $copia->refresh()->ubicacion_id);
        $this->assertDatabaseHas('bitacora', ['accion' => 'configuracion.editar_ubicacion']);
    }

    public function test_no_se_repite_el_nombre_de_otra(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        Ubicacion::create(['nombre' => 'Sede Porvenir']);
        $otra = Ubicacion::create(['nombre' => 'Sede Natales']);

        $this->patchJson("/api/ubicaciones/{$otra->id}", ['nombre' => 'Sede Porvenir'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'Ya existe una ubicación con ese nombre.');
    }

    public function test_se_elimina_una_ubicacion_sin_copias(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ubicacion = Ubicacion::create(['nombre' => 'Bodega']);

        $this->deleteJson("/api/ubicaciones/{$ubicacion->id}")->assertOk();

        $this->assertModelMissing($ubicacion);
        $this->assertDatabaseHas('bitacora', ['accion' => 'configuracion.eliminar_ubicacion']);
    }

    public function test_con_copias_pide_a_donde_pasan_y_las_mueve_antes_de_eliminarla(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $vieja = Ubicacion::create(['nombre' => 'CIGA_DOMO']);
        $nueva = Ubicacion::create(['nombre' => 'Sala de colecciones']);
        $copias = Ejemplar::factory()->count(2)->for(Libro::factory())->create(['ubicacion_id' => $vieja->id]);

        $this->deleteJson("/api/ubicaciones/{$vieja->id}")->assertStatus(422);
        $this->assertModelExists($vieja);

        $this->deleteJson("/api/ubicaciones/{$vieja->id}", ['mover_a' => $vieja->id])->assertStatus(422);

        $this->deleteJson("/api/ubicaciones/{$vieja->id}", ['mover_a' => $nueva->id])
            ->assertOk()
            ->assertJsonPath('copias_movidas', 2);

        $this->assertModelMissing($vieja);
        $copias->each(fn (Ejemplar $c) => $this->assertSame($nueva->id, $c->refresh()->ubicacion_id));
    }

    public function test_no_se_elimina_si_tiene_dias_sin_atencion(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ubicacion = Ubicacion::create(['nombre' => 'Puerto Williams']);
        DiaSinAtencion::create(['fecha' => '2026-12-24', 'ubicacion_id' => $ubicacion->id, 'motivo' => 'Cierre']);

        $this->deleteJson("/api/ubicaciones/{$ubicacion->id}")->assertStatus(409);

        $this->assertModelExists($ubicacion);
    }

    public function test_sin_permiso_de_catalogos_no_se_edita_ni_elimina(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $ubicacion = Ubicacion::create(['nombre' => 'Bodega']);

        $this->patchJson("/api/ubicaciones/{$ubicacion->id}", ['nombre' => 'Otra'])->assertForbidden();
        $this->deleteJson("/api/ubicaciones/{$ubicacion->id}")->assertForbidden();
    }

    public function test_administracion_ve_cuantas_copias_tiene_cada_una(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ubicacion = Ubicacion::create(['nombre' => 'Hemeroteca']);
        Ejemplar::factory()->count(3)->for(Libro::factory())->create(['ubicacion_id' => $ubicacion->id]);

        $fila = collect($this->getJson('/api/ubicaciones?con_copias=1')->assertOk()->json())->firstWhere('id', $ubicacion->id);

        $this->assertSame(3, $fila['copias']);
    }
}
