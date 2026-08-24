<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalaDevolucionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmar_devolucion_de_llave_marca_la_reserva_como_finalizada(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Juan Pérez']);
        Sanctum::actingAs($staff);

        $reserva = Reserva::factory()->create([
            'sala_id' => Sala::factory()->create()->id,
            'hora_devolucion_real' => null,
            'devuelto_por' => null,
            'estado' => 'activa',
        ]);

        $response = $this->patchJson("/api/reservas/{$reserva->id}/devolver");

        $response->assertStatus(200)
            ->assertJsonPath('devuelto_por', 'Juan Pérez')
            ->assertJsonPath('estado', 'finalizada');

        $this->assertDatabaseHas('reservas', [
            'id' => $reserva->id,
            'devuelto_por' => 'Juan Pérez',
            'devuelto_por_staff_id' => $staff->id,
            'estado' => 'finalizada',
        ]);

        $reserva->refresh();
        $this->assertNotNull($reserva->hora_devolucion_real);
    }

    public function test_confirmar_devolucion_de_una_reserva_ya_devuelta_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $reserva = Reserva::factory()->create([
            'sala_id' => Sala::factory()->create()->id,
            'hora_devolucion_real' => now(),
            'devuelto_por' => 'Primer Registro',
            'estado' => 'finalizada',
        ]);

        $response = $this->patchJson("/api/reservas/{$reserva->id}/devolver");

        $response->assertStatus(409);

        $this->assertDatabaseHas('reservas', [
            'id' => $reserva->id,
            'devuelto_por' => 'Primer Registro',
        ]);
    }
}
