<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservaLibroTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservar_libro_con_codigo_no_encontrado_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => 'NO-EXISTE',
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(404);
    }

    public function test_reservar_libro_ya_no_disponible_se_une_a_la_cola_de_espera(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('estado', 'en_cola')
            ->assertJsonPath('fecha_retiro', null);
        // El ejemplar no se toca: ya estaba ocupado por otra persona, sigue igual.
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
    }

    public function test_reservar_libro_que_no_esta_en_estante_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'inventario']);

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(409);
    }

    public function test_reservar_libro_disponible_lo_marca_como_no_disponible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('ejemplar_id', $ejemplar->id)
            ->assertJsonPath('libro_id', $ejemplar->libro_id);
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
    }

    public function test_cancelar_reserva_libera_el_ejemplar_y_marca_cancelado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $reserva = ReservaLibro::factory()->create([
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'usuario_id' => Usuario::factory()->create()->id,
            'estado' => 'pendiente',
        ]);

        $response = $this->patchJson("/api/reservas-libro/{$reserva->id}/cancelar");

        $response->assertStatus(200)->assertJsonPath('estado', 'cancelado');
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => true]);
    }

    public function test_listado_filtra_por_usuario_id(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuarioA = Usuario::factory()->create();
        $usuarioB = Usuario::factory()->create();

        ReservaLibro::factory()->create(['usuario_id' => $usuarioA->id]);
        ReservaLibro::factory()->create(['usuario_id' => $usuarioB->id]);

        $response = $this->getJson("/api/reservas-libro?usuario_id={$usuarioA->id}");

        $response->assertStatus(200)->assertJsonCount(1);
    }
}
