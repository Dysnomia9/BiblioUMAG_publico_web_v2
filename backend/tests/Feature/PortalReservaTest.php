<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use App\Services\ReservaSalaService;
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
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
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

        // PortalController::cancelarReservaSala devuelve 403 (no 404) a quien no la
        // hizo ni va como integrante.
        $response->assertStatus(403);

        $this->assertDatabaseHas('reservas', ['id' => $reserva->id]);
    }

    public function test_un_integrante_no_cancela_la_reserva_de_otro_pero_puede_salirse(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        [$dueno, $companero, $yo, $amiga] = Usuario::factory()->count(4)->create();
        $reserva = Reserva::factory()->conParticipantes([$dueno, $companero, $yo])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'cantidad_personas' => 3,
        ]);

        Sanctum::actingAs($yo);
        $this->deleteJson("/api/mi/reservas/{$reserva->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Esta reserva la hizo otra persona: puedes salirte de ella, pero solo quien la hizo la cancela.');

        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertOk()->assertJsonPath('cancelada', false);

        $reserva->refresh();
        $this->assertSame('activa', $reserva->estado);
        $this->assertSame(2, $reserva->cantidad_personas);
        $this->assertEqualsCanonicalizing([$dueno->id, $companero->id], $reserva->participantes()->pluck('usuarios.id')->all());
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.integrantes', 'entidad_id' => $reserva->id]);

        // Ya no está en la reserva: no puede volver a salirse, y queda libre para otra sala a esa hora.
        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertStatus(403);
        $this->postJson('/api/mi/reservas', [
            'sala_id' => Sala::factory()->create()->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => [$yo->rut, $amiga->rut],
        ])->assertStatus(201);
    }

    public function test_si_al_salirse_quedaria_una_sola_persona_la_reserva_se_cancela(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        [$dueno, $yo] = Usuario::factory()->count(2)->create();
        $reserva = Reserva::factory()->conParticipantes([$dueno, $yo])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'cantidad_personas' => 2,
        ]);

        Sanctum::actingAs($yo);
        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertOk()->assertJsonPath('cancelada', true);

        $this->assertDatabaseHas('reservas', ['id' => $reserva->id, 'estado' => 'cancelada', 'cantidad_personas' => 2]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.cancelar', 'entidad_id' => $reserva->id]);
    }

    public function test_quien_hizo_la_reserva_no_se_sale_la_cancela(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        [$dueno, $companero] = Usuario::factory()->count(2)->create();
        $reserva = Reserva::factory()->conParticipantes([$dueno, $companero])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'cantidad_personas' => 2,
        ]);

        Sanctum::actingAs($dueno);
        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Tú hiciste esta reserva: para dejarla, cancélala.');
        $this->assertDatabaseHas('reservas', ['id' => $reserva->id, 'estado' => 'activa']);
    }

    public function test_no_se_sale_de_una_reserva_que_ya_tiene_la_llave(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 30));
        [$dueno, $companero, $yo] = Usuario::factory()->count(3)->create();
        $reserva = Reserva::factory()->conParticipantes([$dueno, $companero, $yo])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'cantidad_personas' => 3,
            'fecha' => today()->toDateString(),
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'hora_prestamo_real' => today()->setTime(10, 5),
        ]);

        Sanctum::actingAs($yo);
        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")
            ->assertStatus(409)
            ->assertJsonPath('message', 'El grupo ya está usando la sala: la reserva se cierra en el mesón al devolver la llave.');
        $this->assertSame(3, $reserva->participantes()->count());
    }

    public function test_el_portal_muestra_como_propia_la_reserva_en_que_la_incluyeron(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $dueno = Usuario::factory()->create();
        $companero = Usuario::factory()->create();
        $sala = Sala::factory()->create();
        Reserva::factory()->conParticipantes([$dueno, $companero])->create([
            'sala_id' => $sala->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
        ]);

        Sanctum::actingAs($companero);
        $tramo = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->firstWhere('id', $sala->id)['tramos'][0];

        $this->assertTrue($tramo['es_mia']);
        $this->assertEqualsCanonicalizing([$dueno->rut, $companero->rut], array_column($tramo['personas'], 'rut'));
    }

    public function test_el_portal_no_expone_los_datos_de_integrantes_de_reservas_ajenas(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $dueno = Usuario::factory()->create();
        $companero = Usuario::factory()->create();
        $otroUsuario = Usuario::factory()->create();
        $sala = Sala::factory()->create();
        Reserva::factory()->conParticipantes([$dueno, $companero])->create([
            'sala_id' => $sala->id,
            'usuario_id' => $dueno->id,
            'rut_usuario' => $dueno->rut,
            'prestado_por' => 'Personal de mesón',
        ]);

        // La sala de la prueba entre las que ya trae la base (migración crear_salas_iniciales).
        $tramoDeLaSala = fn () => collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->firstWhere('id', $sala->id)['tramos'][0];

        Sanctum::actingAs($otroUsuario);
        $ajena = $tramoDeLaSala();

        $this->assertSame('10:00', $ajena['hora_inicio']);
        $this->assertNull($ajena['usuario_id']);
        foreach (['personas', 'rut_usuario', 'prestado_por', 'devuelto_por'] as $campo) {
            $this->assertArrayNotHasKey($campo, $ajena);
        }
        $this->assertStringNotContainsString($companero->rut, $this->getJson('/api/mi/salas')->content());

        Sanctum::actingAs($dueno);
        $propia = $tramoDeLaSala();

        $this->assertSame($dueno->id, $propia['usuario_id']);
        $this->assertEqualsCanonicalizing([$dueno->rut, $companero->rut], array_column($propia['personas'], 'rut'));
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
        // Un viernes: los domingos la biblioteca no atiende y no se reserva.
        Carbon::setTestNow('2026-07-10 06:00:00');
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
     * Sin esto, un estudiante podía tomar salas a nombre de otros: a ellos les quedaba
     * la reserva pendiente (y no podían pedir otra) y a él no le pesaba ningún límite.
     */
    public function test_quien_reserva_desde_el_portal_tiene_que_ir_en_el_grupo(): void
    {
        Carbon::setTestNow('2026-07-10 06:00:00');
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();

        $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 2,
            'ruts' => [$ana->rut, $beto->rut],
        ])->assertStatus(422)->assertJsonPath('message', 'Debes incluir tu propio RUT entre los integrantes de la reserva.');

        $this->assertDatabaseMissing('reservas', ['sala_id' => $sala->id]);
    }

    public function test_quien_reserva_queda_a_cargo_aunque_escriba_su_rut_despues(): void
    {
        Carbon::setTestNow('2026-07-10 06:00:00');
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();
        $otro = Usuario::factory()->create();

        $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 2,
            'ruts' => [$otro->rut, $usuario->rut],
        ])->assertStatus(201)->assertJsonPath('rut_usuario', $usuario->rut);
    }

    /**
     * Antes esto se rechazaba (regla de "una sala activa a la vez, salvo extender la
     * misma"), eliminada con el horario continuo — ahora un mismo usuario puede tener
     * reservas en salas distintas el mismo día siempre que no se solapen en horario y
     * quepan dentro de la cuota diaria (ver R6/R5 de la spec).
     */
    public function test_usuario_puede_reservar_otra_sala_sin_solape_de_horario(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        $salaVieja = Sala::factory()->create();
        $salaNueva = Sala::factory()->create();

        // Ya la usaron: una reserva pendiente sí impediría pedir otra (ReservaPendienteUnicaTest).
        Reserva::factory()->conParticipantes([$usuario, $otro])->create([
            'sala_id' => $salaVieja->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'estado' => 'finalizada',
            'hora_prestamo_real' => '2026-07-10 10:02:00',
            'hora_devolucion_real' => '2026-07-10 10:58:00',
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
        Carbon::setTestNow('2026-07-10 12:30:00');
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
            'estado' => 'finalizada',
            'hora_prestamo_real' => '2026-07-10 08:01:00',
            'hora_devolucion_real' => '2026-07-10 11:59:00',
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

    public function test_usuario_no_puede_reservar_el_domingo(): void
    {
        Carbon::setTestNow('2026-07-12 09:00:00');
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();
        $otro = Usuario::factory()->create();

        $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-07-12',
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $otro->rut],
        ])->assertStatus(422)->assertJsonPath('message', 'La biblioteca no atiende los domingos: elige otro día.');

        $this->getJson('/api/mi/salas')->assertOk()->assertJsonPath('cerrado', true);
    }

    public function test_el_portal_recibe_el_horario_de_salas_del_dia(): void
    {
        Carbon::setTestNow('2026-07-11 10:00:00');
        Sanctum::actingAs(Usuario::factory()->create());
        Sala::factory()->create();

        $this->getJson('/api/mi/salas')->assertOk()
            ->assertJsonPath('atencion', ['09:00', '14:00'])
            ->assertJsonPath('cerrado', false);
    }

    public function test_desde_el_portal_no_se_cancela_lo_que_ya_ocurrio(): void
    {
        $usuario = Usuario::factory()->create();
        $reserva = fn (array $datos) => Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'cantidad_personas' => 1,
            ...$datos,
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(9, 0));
        $inasistencia = $reserva(['fecha' => Carbon::yesterday()->toDateString(), 'estado' => 'no_show']);
        $finalizada = $reserva(['fecha' => Carbon::yesterday()->toDateString(), 'estado' => 'finalizada']);
        $enUso = $reserva(['hora_inicio' => '09:00:00', 'hora_fin' => '10:00:00', 'hora_prestamo_real' => now()]);
        $porVencer = $reserva(['hora_inicio' => '10:00:00', 'hora_fin' => '11:00:00']);

        Sanctum::actingAs($usuario);

        $this->deleteJson("/api/mi/reservas/{$inasistencia->id}")->assertStatus(409)
            ->assertJsonPath('message', 'El grupo no llegó a tiempo a esta reserva: quedó registrada como inasistencia y ya no se puede cancelar.');
        $this->deleteJson("/api/mi/reservas/{$finalizada->id}")->assertStatus(409);
        $this->deleteJson("/api/mi/reservas/{$enUso->id}")->assertStatus(409);

        // Pasados los 15 minutos sin llegar, ya no se cancela: queda como inasistencia.
        Carbon::setTestNow(Carbon::today()->setTime(10, 20));
        $this->deleteJson("/api/mi/reservas/{$porVencer->id}")->assertStatus(409);

        $this->assertSame('no_show', $inasistencia->fresh()->estado);
        $this->assertSame('finalizada', $finalizada->fresh()->estado);
        $this->assertSame('activa', $enUso->fresh()->estado);
        $this->assertSame('no_show', $porVencer->fresh()->estado);
    }

    public function test_quien_hizo_la_reserva_cancela_una_al_dia_sin_espera_y_desde_la_segunda_espera(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        $sala = Sala::factory()->create();
        Sanctum::actingAs($usuario);
        $reservar = fn (string $inicio, string $fin) => $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $otro->rut],
        ]);
        $cancelaciones = fn () => $this->getJson('/api/mi/salas')->json('cancelaciones');

        $this->assertSame(1, $cancelaciones()['sin_espera_restantes']);
        $primera = $reservar('10:00', '11:00')->assertStatus(201)->json('id');
        $this->deleteJson("/api/mi/reservas/{$primera}")->assertStatus(204);

        // La primera cancelación del día no tiene consecuencias.
        $this->assertSame(0, $cancelaciones()['sin_espera_restantes']);
        $this->assertNull($cancelaciones()['espera']);
        $segunda = $reservar('11:00', '12:00')->assertStatus(201)->json('id');
        $this->deleteJson("/api/mi/reservas/{$segunda}")->assertStatus(204);

        // Desde la segunda, cinco minutos sin lanzar otra.
        $aviso = 'Ya cancelaste más de una reserva hoy. Para evitar reservas y cancelaciones seguidas, hay que esperar 5 minutos: podrás reservar una sala de nuevo a las 09:05.';
        $reservar('12:00', '13:00')->assertStatus(429)->assertJsonPath('message', $aviso);
        $this->assertSame($aviso, $cancelaciones()['espera']);
        $this->assertSame(2, Reserva::where('usuario_id', $usuario->id)->count());

        Carbon::setTestNow('2026-07-10 09:05:00');
        $this->assertNull($cancelaciones()['espera']);
        $reservar('12:00', '13:00')->assertStatus(201);
    }

    public function test_si_un_integrante_se_sale_no_le_pesa_a_nadie_y_quien_espera_puede_ir_en_reservas_ajenas(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        $dueno = Usuario::factory()->create();
        $yo = Usuario::factory()->create();
        $sala = Sala::factory()->create();

        // Me ponen en dos reservas sin preguntarme y me salgo (eran de 2, así que se cancelan): no quedo esperando, ni quien las hizo.
        foreach ([['10:00:00', '11:00:00'], ['11:00:00', '12:00:00']] as [$inicio, $fin]) {
            $reserva = Reserva::factory()->conParticipantes([$dueno, $yo])->create([
                'sala_id' => $sala->id,
                'usuario_id' => $dueno->id,
                'rut_usuario' => $dueno->rut,
                'cantidad_personas' => 2,
                'hora_inicio' => $inicio,
                'hora_fin' => $fin,
            ]);
            Sanctum::actingAs($yo);
            $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertOk()->assertJsonPath('cancelada', true);
        }
        $this->getJson('/api/mi/salas')->assertJsonPath('cancelaciones.espera', null)->assertJsonPath('cancelaciones.sin_espera_restantes', 1);
        Sanctum::actingAs($dueno);
        $this->getJson('/api/mi/salas')->assertJsonPath('cancelaciones.espera', null)->assertJsonPath('cancelaciones.sin_espera_restantes', 1);

        // Aunque yo esté esperando, otros pueden incluirme en sus reservas.
        $servicio = app(ReservaSalaService::class);
        $servicio->registrarCancelacionDelPortal($yo);
        $servicio->registrarCancelacionDelPortal($yo);
        $this->assertNotNull($servicio->esperaParaReservar($yo));
        $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '14:00',
            'hora_fin' => '15:00',
            'cantidad_personas' => 2,
            'ruts' => [$dueno->rut, $yo->rut],
        ])->assertStatus(201);
    }
}
