<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Una cuenta inactiva no marca entrada ni entra al portal: tampoco se lleva ni reserva material. */
class UsuarioInactivoPrestamoTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_presta_un_libro_a_un_usuario_inactivo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => false]);
        $ejemplar = Ejemplar::factory()->create();

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ])->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'inactivo'));

        $this->assertDatabaseCount('prestamos', 0);
        $this->assertTrue($ejemplar->fresh()->disponible);
    }

    public function test_no_se_presta_un_recurso_de_apoyo_a_un_usuario_inactivo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => false]);
        $equipo = Equipo::factory()->create(['tipo' => 'audifonos']);

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'audifonos',
            'codigo_barras' => $equipo->codigo_barras,
        ])->assertStatus(409);

        $this->assertDatabaseCount('prestamos', 0);
    }

    public function test_no_se_reserva_un_libro_a_un_usuario_inactivo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => false]);
        $ejemplar = Ejemplar::factory()->create();

        $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ])->assertStatus(409);

        $this->assertDatabaseCount('reservas_libro', 0);
    }

    public function test_las_condiciones_de_prestamo_avisan_que_el_usuario_esta_inactivo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => false]);
        $ejemplar = Ejemplar::factory()->create();

        $this->getJson('/api/prestamos/condiciones?usuario_id='.$usuario->id.'&codigo_barras='.$ejemplar->codigo_barras)
            ->assertOk()
            ->assertJsonPath('motivo_rechazo', fn ($m) => str_contains((string) $m, 'inactivo'));
    }

    public function test_habilitado_el_usuario_si_se_le_presta(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => true]);
        $ejemplar = Ejemplar::factory()->create();

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ])->assertCreated();
    }
}
