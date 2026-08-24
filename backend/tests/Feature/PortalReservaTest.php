<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalReservaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_usuario_puede_cancelar_su_propia_reserva_y_no_se_borra_la_fila(): void
    {
        $usuario = Usuario::factory()->create();
        $sala = Sala::factory()->create();
        $reserva = Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => $sala->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'cantidad_personas' => 1,
        ]);

        Sanctum::actingAs($usuario);
        $response = $this->deleteJson("/api/mi/reservas/{$reserva->id}");

        $response->assertStatus(204);
        $this->assertDatabaseHas('reservas', ['id' => $reserva->id, 'estado' => 'cancelada']);
    }

    public function test_usuario_no_puede_cancelar_reserva_que_no_le_pertenece(): void
    {
        $dueno = Usuario::factory()->create();
        $otroUsuario = Usuario::factory()->create();
        $sala = Sala::factory()->create();

        $reserva = Reserva::factory()->conParticipantes([$dueno])->create([
            'sala_id' => $sala->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'cantidad_personas' => 1,
        ]);

        Sanctum::actingAs($otroUsuario);

        $response = $this->deleteJson("/api/mi/reservas/{$reserva->id}");

        // PortalController::cancelarReservaSala compara $reserva->usuario_id contra
        // $request->user()->id y devuelve 403 (no 404) cuando no coinciden.
        $response->assertStatus(403);

        $this->assertDatabaseHas('reservas', ['id' => $reserva->id]);
    }

    public function test_usuario_no_puede_reservar_para_un_dia_futuro(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();

        $response = $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->addDay()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, Usuario::factory()->create()->rut],
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'Solo puedes reservar una sala para el día de hoy');
        $this->assertDatabaseMissing('reservas', ['sala_id' => $sala->id]);
    }

    public function test_usuario_puede_reservar_para_el_dia_de_hoy(): void
    {
        Carbon::setTestNow(now()->setTime(6, 0));
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();
        $otro = Usuario::factory()->create();

        $response = $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $otro->rut],
        ]);

        $response->assertStatus(201);
    }

    /**
     * Antes esto se rechazaba (regla de "una sala activa a la vez, salvo extender la
     * misma"), eliminada con el horario continuo — ahora un mismo usuario puede tener
     * reservas en salas distintas el mismo día siempre que no se solapen en horario y
     * quepan dentro de la cuota diaria (ver R6/R5 de la spec).
     */
    public function test_usuario_puede_reservar_otra_sala_sin_solape_de_horario(): void
    {
        Carbon::setTestNow(now()->setTime(6, 0));
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        $salaVieja = Sala::factory()->create();
        $salaNueva = Sala::factory()->create();

        Reserva::factory()->conParticipantes([$usuario, $otro])->create([
            'sala_id' => $salaVieja->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
        ]);

        Sanctum::actingAs($usuario);

        $response = $this->postJson('/api/mi/reservas', [
            'sala_id' => $salaNueva->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '14:00',
            'hora_fin' => '15:00',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $otro->rut],
        ]);

        $response->assertStatus(201);
    }

    public function test_usuario_no_puede_exceder_la_cuota_diaria(): void
    {
        Carbon::setTestNow(now()->setTime(6, 0));
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        $salaVieja = Sala::factory()->create();
        $salaNueva = Sala::factory()->create();

        Reserva::factory()->conParticipantes([$usuario, $otro])->create([
            'sala_id' => $salaVieja->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 2,
        ]);

        Sanctum::actingAs($usuario);

        $response = $this->postJson('/api/mi/reservas', [
            'sala_id' => $salaNueva->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '14:00',
            'hora_fin' => '14:30',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $otro->rut],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Ya tienes 4 h reservados hoy; el máximo diario es de 4 h.');
    }
}
