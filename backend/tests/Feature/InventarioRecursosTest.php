<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Equipo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inventario de recursos de apoyo: bajas con su motivo. */
class InventarioRecursosTest extends TestCase
{
    use RefreshDatabase;

    public function test_dar_de_baja_pide_el_motivo_y_lo_guarda_con_la_fecha(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $equipo = Equipo::factory()->create(['codigo_inventario' => 'Notebook 03', 'tipo' => 'notebook']);

        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo' => 'Indica el motivo de la baja']);
        $this->assertTrue($equipo->refresh()->activo);

        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => false, 'motivo' => 'Pantalla rota, no se puede reparar'])
            ->assertOk()
            ->assertJsonPath('motivo_baja', 'Pantalla rota, no se puede reparar');

        $equipo->refresh();
        $this->assertFalse($equipo->activo);
        $this->assertNotNull($equipo->dado_de_baja_el);
        $registro = Bitacora::where('accion', 'equipos.dar_de_baja')->sole();
        $this->assertSame('Dio de baja el recurso Notebook 03. Motivo: Pantalla rota, no se puede reparar', $registro->descripcion);
    }

    public function test_reactivar_limpia_el_motivo_de_la_baja(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $equipo = Equipo::factory()->create(['activo' => false, 'motivo_baja' => 'Extraviado', 'dado_de_baja_el' => now()->subWeek()]);

        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => true])->assertOk();

        $equipo->refresh();
        $this->assertTrue($equipo->activo);
        $this->assertNull($equipo->motivo_baja);
        $this->assertNull($equipo->dado_de_baja_el);
    }
}
