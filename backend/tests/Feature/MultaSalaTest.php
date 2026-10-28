<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Multa;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use App\Notifications\MultaGeneradaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Devolver tarde la llave de una sala: al registrar la devolución, el mesón ve cuántos
 * minutos se atrasó el grupo y decide si cursa la multa (no se genera sola).
 * Montos de config/multas.php («sala»): 50 por minuto, tope 5.000, 5 minutos de tolerancia.
 */
class MultaSalaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $titular;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());
        $this->titular = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Soto', 'email' => 'ana@umag.cl']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una reserva de 10:00 a 12:00 del 14-08-2026 que ya recibió su llave. */
    private function reservaEnUso(?Sala $sala = null): Reserva
    {
        return Reserva::factory()->conParticipantes([$this->titular])->create([
            'sala_id' => ($sala ?? Sala::factory()->create(['nombre' => 'Logia 03']))->id,
            'usuario_id' => $this->titular->id,
            'rut_usuario' => $this->titular->rut,
            'fecha' => '2026-08-14',
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'hora_prestamo_real' => '2026-08-14 10:02:00',
        ]);
    }

    private function devolverA(string $hora, Reserva $reserva)
    {
        Carbon::setTestNow("2026-08-14 {$hora}");

        return $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk();
    }

    public function test_devolver_tarde_avisa_los_minutos_y_el_monto_sin_multar_solo(): void
    {
        $reserva = $this->reservaEnUso();

        $this->devolverA('12:20:00', $reserva)
            ->assertJsonPath('atraso_devolucion.minutos', 20)
            ->assertJsonPath('atraso_devolucion.monto', 1000)
            ->assertJsonPath('atraso_devolucion.persona', 'Ana Soto')
            ->assertJsonPath('atraso_devolucion.sala', 'Logia 03')
            ->assertJsonPath('atraso_devolucion.hora_fin', '12:00')
            ->assertJsonPath('atraso_devolucion.monto_provisional', true);

        $this->assertSame(0, Multa::count());
    }

    public function test_a_tiempo_o_dentro_de_la_tolerancia_no_avisa(): void
    {
        $this->devolverA('11:50:00', $this->reservaEnUso())->assertJsonPath('atraso_devolucion', null);
        $this->devolverA('12:05:00', $this->reservaEnUso())->assertJsonPath('atraso_devolucion', null);
    }

    public function test_si_el_meson_decide_multar_la_multa_es_del_titular_y_no_se_duplica(): void
    {
        $reserva = $this->reservaEnUso();
        $this->devolverA('12:20:00', $reserva);

        $this->postJson("/api/reservas/{$reserva->id}/multa-atraso")->assertCreated();
        $this->postJson("/api/reservas/{$reserva->id}/multa-atraso")->assertStatus(409);

        $multa = Multa::sole();
        $this->assertSame($this->titular->id, $multa->usuario_id);
        $this->assertSame($reserva->id, $multa->reserva_id);
        $this->assertSame(['atraso', 'pendiente', 'sistema', 1000], [$multa->tipo, $multa->estado, $multa->origen, $multa->monto]);
        $this->assertStringContainsString('Logia 03 con 20 minutos de atraso', $multa->detalle);
        $this->assertTrue(Bitacora::where('accion', 'multas.generar')->where('entidad_id', $multa->id)->exists());
        Notification::assertSentTo($this->titular, MultaGeneradaNotification::class);

        // Ya devuelta la llave con su multa, el aviso no vuelve a aparecer.
        $this->assertNull(app(\App\Services\MultaService::class)->atrasoDeSala($reserva->fresh()));
    }

    public function test_el_monto_tiene_tope(): void
    {
        $this->devolverA('16:00:00', $this->reservaEnUso())
            ->assertJsonPath('atraso_devolucion.minutos', 240)
            ->assertJsonPath('atraso_devolucion.monto', 5000);
    }

    public function test_no_se_multa_una_reserva_devuelta_a_tiempo_ni_una_sin_devolver(): void
    {
        $aTiempo = $this->reservaEnUso();
        $this->devolverA('11:59:00', $aTiempo);
        $this->postJson("/api/reservas/{$aTiempo->id}/multa-atraso")->assertStatus(409);

        $sinDevolver = $this->reservaEnUso();
        $this->postJson("/api/reservas/{$sinDevolver->id}/multa-atraso")->assertStatus(409);

        $this->assertSame(0, Multa::count());
    }

    public function test_con_las_multas_de_salas_apagadas_no_avisa(): void
    {
        config(['multas.sala.activa' => false]);
        $reserva = $this->reservaEnUso();

        $this->devolverA('12:30:00', $reserva)->assertJsonPath('atraso_devolucion', null);
        $this->postJson("/api/reservas/{$reserva->id}/multa-atraso")->assertStatus(409);
    }

    public function test_el_escaneo_tardio_de_la_logia_registra_la_devolucion_y_avisa(): void
    {
        $logia = Sala::factory()->create(['nombre' => 'Logia 07', 'tipo' => 'logia', 'codigo' => 'X07']);
        $reserva = $this->reservaEnUso($logia);
        Carbon::setTestNow('2026-08-14 12:40:00');

        $this->postJson('/api/salas/llave', ['codigo' => 'X07'])->assertOk()
            ->assertJsonPath('id', $reserva->id)
            ->assertJsonPath('atraso_devolucion.minutos', 40)
            ->assertJsonPath('atraso_devolucion.sala', 'Logia 07');

        $this->assertSame('finalizada', $reserva->fresh()->estado);
    }

    public function test_si_la_llave_sigue_afuera_el_escaneo_la_devuelve_antes_de_entregar_al_grupo_siguiente(): void
    {
        $logia = Sala::factory()->create(['tipo' => 'logia', 'codigo' => 'X08']);
        $anterior = $this->reservaEnUso($logia);
        $siguiente = Reserva::factory()->conParticipantes([Usuario::factory()->create()])->create([
            'sala_id' => $logia->id, 'fecha' => '2026-08-14', 'hora_inicio' => '12:00:00', 'hora_fin' => '13:00:00',
        ]);
        Carbon::setTestNow('2026-08-14 12:15:00');

        $this->postJson('/api/salas/llave', ['codigo' => 'X08'])->assertOk()->assertJsonPath('id', $anterior->id);
        $this->postJson('/api/salas/llave', ['codigo' => 'X08'])->assertOk()->assertJsonPath('id', $siguiente->id);

        $this->assertNotNull($anterior->fresh()->hora_devolucion_real);
        $this->assertNotNull($siguiente->fresh()->hora_prestamo_real);
    }

    public function test_si_el_correo_falla_la_multa_de_sala_queda_cursada(): void
    {
        Exceptions::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('El servidor de correo no responde'));
        $reserva = $this->reservaEnUso();
        $this->devolverA('12:20:00', $reserva);

        $this->postJson("/api/reservas/{$reserva->id}/multa-atraso")->assertCreated();

        $this->assertSame($reserva->id, Multa::sole()->reserva_id);
        Exceptions::assertReported(\RuntimeException::class);
    }
}
