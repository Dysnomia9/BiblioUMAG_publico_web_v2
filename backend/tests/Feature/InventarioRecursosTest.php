<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Equipo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inventario de recursos de apoyo: bajas con su motivo y registro de varios a la vez. */
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

    public function test_se_registran_varios_recursos_de_una_vez(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/equipos/lote', ['recursos' => [
            ['codigo_inventario' => 'Notebook 06', 'codigo_barras' => 'NB-06', 'tipo' => 'notebook'],
            ['codigo_inventario' => 'Notebook 07', 'codigo_barras' => 'NB-07', 'tipo' => 'notebook'],
            ['codigo_inventario' => 'Cargador 04', 'codigo_barras' => 'CAR-04', 'tipo' => 'cargador'],
        ]])->assertCreated()->assertJsonCount(3);

        $this->assertSame(['CAR-04', 'NB-06', 'NB-07'], Equipo::orderBy('codigo_barras')->pluck('codigo_barras')->all());
        $this->assertTrue(Equipo::where('codigo_barras', 'NB-06')->value('disponible'));
        $this->assertSame(3, Bitacora::where('accion', 'equipos.agregar')->count());
    }

    public function test_si_una_fila_tiene_un_error_no_se_registra_ninguna(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Equipo::factory()->create(['codigo_inventario' => 'Notebook 01', 'codigo_barras' => 'NB-01', 'tipo' => 'notebook']);

        $this->postJson('/api/equipos/lote', ['recursos' => [
            ['codigo_inventario' => 'Notebook 08', 'codigo_barras' => 'NB-08', 'tipo' => 'notebook'],
            ['codigo_inventario' => 'Notebook 09', 'codigo_barras' => 'NB-01', 'tipo' => 'notebook'],
            ['codigo_inventario' => 'Notebook 10', 'codigo_barras' => 'NB-08', 'tipo' => 'notebook'],
            ['codigo_inventario' => 'Notebook 11', 'codigo_barras' => 'NB-11', 'tipo' => 'no-existe'],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'recursos.1.codigo_barras' => 'Fila 2: ese código de barras ya es de otro recurso o de una copia de un libro.',
                'recursos.2.codigo_barras' => 'Fila 3: ese código de barras está repetido en la lista.',
                'recursos.3.tipo' => 'Fila 4: esa categoría no existe o está desactivada.',
            ]);

        $this->assertSame(1, Equipo::count());
    }

    public function test_sin_permiso_de_recursos_no_se_registra_el_lote(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->postJson('/api/equipos/lote', ['recursos' => [
            ['codigo_inventario' => 'Audífonos 09', 'codigo_barras' => 'AUD-09', 'tipo' => 'audifonos'],
        ]])->assertForbidden();

        $this->assertSame(0, Equipo::count());
    }
}
