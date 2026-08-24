<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Entrada;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAtribucionTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_prestamo_estampa_el_staff_autenticado_sin_pedirlo_en_el_body(): void
    {
        $staffA = Staff::factory()->create(['nombre' => 'Ana Pérez']);
        Sanctum::actingAs($staffA);

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
            // Un intento de suplantar a otro staff vía el body no debe tener efecto.
            'prestado_por' => 'Alguien Más',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('prestado_por', 'Ana Pérez')
            ->assertJsonPath('prestado_por_staff_id', $staffA->id);
    }

    public function test_devolver_prestamo_estampa_el_staff_autenticado(): void
    {
        $staffB = Staff::factory()->create(['nombre' => 'Bruno Ríos']);
        Sanctum::actingAs($staffB);

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'audifonos',
            'fecha_devolucion' => null,
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $response->assertStatus(200)
            ->assertJsonPath('devuelto_por', 'Bruno Ríos')
            ->assertJsonPath('devuelto_por_staff_id', $staffB->id);
    }

    public function test_escanear_logia_estampa_el_staff_autenticado_sin_pedirlo_en_el_body(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Carla Soto']);
        Sanctum::actingAs($staff);

        $sala = Sala::factory()->create(['codigo_barras' => '90099', 'tipo' => 'logia']);
        $reserva = Reserva::factory()->create([
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => now()->format('H:00'),
            'hora_fin' => now()->addHour()->format('H:00'),
            'estado' => 'activa',
        ]);

        $response = $this->postJson('/api/salas/scan-logia', [
            'codigo_barras' => '90099',
            // Un intento de suplantar a otro staff vía el body no debe tener efecto.
            'registrado_por' => 'Alguien Más',
        ]);

        $response->assertStatus(200)->assertJsonPath('prestado_por', 'Carla Soto');
        $this->assertDatabaseHas('reservas', [
            'id' => $reserva->id,
            'prestado_por_staff_id' => $staff->id,
        ]);
    }

    public function test_crear_reserva_de_libro_desde_staff_estampa_el_staff_autenticado(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Diego Vera']);
        Sanctum::actingAs($staff);

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reservas_libro', [
            'usuario_id' => $usuario->id,
            'registrado_por_staff_id' => $staff->id,
        ]);
    }

    public function test_crear_reserva_de_libro_desde_el_portal_no_tiene_staff_asociado(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->postJson('/api/mi/reservas-libro', [
            'libro_id' => $ejemplar->libro_id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reservas_libro', [
            'usuario_id' => $usuario->id,
            'registrado_por_staff_id' => null,
        ]);
    }

    public function test_registrar_entrada_desde_staff_estampa_el_staff_autenticado(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Elena Rojas']);
        Sanctum::actingAs($staff);

        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/entrada', ['rut' => $usuario->rut]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('entradas', [
            'usuario_id' => $usuario->id,
            'registrado_por_staff_id' => $staff->id,
        ]);
    }

    public function test_registrar_entrada_desde_el_portal_no_tiene_staff_asociado(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $response = $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('entradas', [
            'usuario_id' => $usuario->id,
            'registrado_por_staff_id' => null,
        ]);
    }
}
