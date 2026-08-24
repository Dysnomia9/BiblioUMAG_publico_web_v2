<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalaConfirmacionAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_plazo_de_confirmacion_corre_desde_el_inicio_del_bloque_si_se_reservo_con_anticipacion(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $this->assertSame('2026-08-15 14:15:00', $reserva->plazoConfirmacion()->toDateTimeString());
    }

    public function test_plazo_de_confirmacion_corre_desde_la_reserva_si_se_reservo_ahora_dentro_de_un_bloque_ya_iniciado(): void
    {
        Carbon::setTestNow('2026-08-15 15:00:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $this->assertSame('2026-08-15 15:15:00', $reserva->plazoConfirmacion()->toDateTimeString());
    }

    public function test_confirmar_llegada_dentro_del_plazo_marca_prestado_por_y_hora(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Ana Pérez']);
        Sanctum::actingAs($staff);
        Carbon::setTestNow('2026-08-15 14:05:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $response = $this->patchJson("/api/reservas/{$reserva->id}/llegada");

        $response->assertStatus(200)->assertJsonPath('prestado_por', 'Ana Pérez');
        $this->assertNotNull($response->json('hora_prestamo_real'));
        $this->assertDatabaseHas('reservas', ['id' => $reserva->id, 'prestado_por_staff_id' => $staff->id]);
    }

    public function test_confirmar_llegada_dos_veces_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-15 14:05:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertStatus(200);
        $response = $this->patchJson("/api/reservas/{$reserva->id}/llegada");

        $response->assertStatus(409);
    }

    public function test_confirmar_llegada_pasado_el_plazo_devuelve_409_y_libera_la_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-15 09:00:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        Carbon::setTestNow('2026-08-15 14:16:00');
        $response = $this->patchJson("/api/reservas/{$reserva->id}/llegada");

        $response->assertStatus(409);
        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_liberar_reserva_vencida_la_marca_no_show(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-15 09:00:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        Carbon::setTestNow('2026-08-15 14:20:00');
        $response = $this->patchJson("/api/reservas/{$reserva->id}/liberar");

        $response->assertStatus(200)->assertJsonPath('estado', 'no_show');
    }

    public function test_liberar_reserva_todavia_dentro_del_plazo_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-15 14:05:00');
        $reserva = Reserva::factory()->create(['fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $response = $this->patchJson("/api/reservas/{$reserva->id}/liberar");

        $response->assertStatus(409);
    }

    public function test_reservar_el_mismo_bloque_despues_de_vencido_el_plazo_anterior_es_posible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $sala = Sala::factory()->create();

        Carbon::setTestNow('2026-08-15 09:00:00');
        Reserva::factory()->conParticipantes(Usuario::factory()->count(2)->create())->create([
            'sala_id' => $sala->id,
            'fecha' => '2026-08-15',
            'hora_inicio' => '14:00',
            'hora_fin' => '16:00',
            'cantidad_personas' => 2,
        ]);

        Carbon::setTestNow('2026-08-15 14:16:00');
        $nuevos = Usuario::factory()->count(2)->create();

        // El nuevo intento arranca en 14:30 (el próximo tramo alineado a la
        // granularidad desde "ahora"), no en el 14:00 original — con horario continuo,
        // un tramo cuyo inicio ya pasó no se puede volver a reservar tal cual (ver
        // ReservaSalaService::validarTramo(), regla nueva de "hora ya pasada"); lo que
        // importa acá es que la sala vuelve a estar libre tras el no-show, no el
        // horario exacto de la reserva original.
        $response = $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-08-15',
            'hora_inicio' => '14:30',
            'hora_fin' => '16:00',
            'cantidad_personas' => 2,
            'ruts' => $nuevos->pluck('rut')->all(),
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Reserva::where('sala_id', $sala->id)->where('estado', 'no_show')->count());
    }

    public function test_index_excluye_no_show_y_expira_perezosamente(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Carbon::setTestNow('2026-08-15 09:00:00');
        $reserva = Reserva::factory()->create(['sala_id' => Sala::factory()->create()->id, 'fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        Carbon::setTestNow('2026-08-15 14:20:00');
        $response = $this->getJson('/api/salas?fecha=2026-08-15');

        $response->assertStatus(200);
        $sala = collect($response->json('salas'))->firstWhere('id', $reserva->sala_id);
        $this->assertSame([], $sala['tramos']);
        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_index_expone_plazo_confirmacion_y_vencida_sin_confirmar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-15 09:00:00');
        $reserva = Reserva::factory()->create(['sala_id' => Sala::factory()->create()->id, 'fecha' => '2026-08-15', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $response = $this->getJson('/api/salas?fecha=2026-08-15');

        $response->assertStatus(200);
        $sala = collect($response->json('salas'))->firstWhere('id', $reserva->sala_id);
        $tramo = $sala['tramos'][0];

        $this->assertFalse($tramo['vencida_sin_confirmar']);
        $plazoDevuelto = Carbon::parse($tramo['plazo_confirmacion']);
        $this->assertTrue($plazoDevuelto->equalTo($reserva->plazoConfirmacion()));
    }
}
