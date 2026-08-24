<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipoPrestamoTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestamo_de_equipo_con_codigo_no_registrado_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'audifonos',
            'codigo_barras' => '7509999999999',
        ]);

        $response->assertStatus(404);
    }

    public function test_prestamo_de_equipo_ya_prestado_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['tipo' => 'audifonos', 'disponible' => false]);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'audifonos',
            'codigo_barras' => $equipo->codigo_barras,
        ]);

        $response->assertStatus(409);
    }

    public function test_prestamo_de_equipo_dado_de_baja_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['tipo' => 'notebook', 'activo' => false]);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'notebook',
            'codigo_barras' => $equipo->codigo_barras,
        ]);

        $response->assertStatus(409);
    }

    public function test_prestamo_de_equipo_marca_equipo_como_no_disponible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'notebook',
            'codigo_barras' => $equipo->codigo_barras,
        ]);

        $response->assertStatus(201)->assertJsonPath('equipo_id', $equipo->id);

        $this->assertDatabaseHas('equipos', ['id' => $equipo->id, 'disponible' => false]);
    }

    public function test_prestamo_de_cargador_funciona_igual_que_otros_equipos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['tipo' => 'cargador']);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'cargador',
            'codigo_barras' => $equipo->codigo_barras,
        ]);

        $response->assertStatus(201)->assertJsonPath('equipo_id', $equipo->id);

        $this->assertDatabaseHas('equipos', ['id' => $equipo->id, 'disponible' => false]);
    }

    public function test_buscar_equipo_por_codigo_de_barras(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);

        $response = $this->getJson("/api/equipos/{$equipo->codigo_barras}");

        $response->assertStatus(200)->assertJsonPath('id', $equipo->id);
    }

    public function test_buscar_equipo_por_codigo_de_barras_no_encontrado_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->getJson('/api/equipos/7509999999999');

        $response->assertStatus(404);
    }

    public function test_devolver_prestamo_de_equipo_libera_disponibilidad(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $equipo = Equipo::factory()->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'equipo_id' => $equipo->id,
            'tipo_item' => $equipo->tipo,
            'fecha_devolucion' => null,
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $response->assertStatus(200);

        $this->assertDatabaseHas('equipos', ['id' => $equipo->id, 'disponible' => true]);
    }

    public function test_no_se_puede_dar_de_baja_un_equipo_actualmente_prestado(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $equipo = Equipo::factory()->create(['disponible' => false]);

        $response = $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => false]);

        $response->assertStatus(409);

        $this->assertDatabaseHas('equipos', ['id' => $equipo->id, 'activo' => true]);
    }
}
