<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un grupo que devuelve la llave antes de tiempo no recibe castigo: al registrar la
 * devolución, el mesón ve cuánto le quedaba y puede liberar ese rato para otro grupo.
 */
class SalaLiberarRestoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $titular;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
        $this->titular = Usuario::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Logia de 10:00 a 12:00 del viernes 14-08-2026, con la llave entregada a las 10:02. */
    private function reservaEnUso(): Reserva
    {
        return Reserva::factory()->conParticipantes([$this->titular])->create([
            'sala_id' => Sala::factory()->create(['nombre' => 'Logia 04', 'tipo' => 'logia', 'codigo_barras' => 'LOGIA-04'])->id,
            'usuario_id' => $this->titular->id,
            'rut_usuario' => $this->titular->rut,
            'fecha' => '2026-08-14',
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'hora_prestamo_real' => '2026-08-14 10:02:00',
        ]);
    }

    private function reservarAhora(Reserva $reserva)
    {
        $grupo = Usuario::factory()->count(2)->create();

        return $this->postJson('/api/reservas', [
            'sala_id' => $reserva->sala_id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '10:41',
            'hora_fin' => '11:30',
            'cantidad_personas' => 2,
            'inmediata' => true,
            'ruts' => $grupo->pluck('rut')->all(),
        ]);
    }

    public function test_devolver_antes_avisa_cuanto_quedaba_sin_multa(): void
    {
        $reserva = $this->reservaEnUso();
        Carbon::setTestNow('2026-08-14 10:32:40');

        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk()
            ->assertJsonPath('atraso_devolucion', null)
            ->assertJsonPath('tiempo_sin_usar.minutos', 87)
            ->assertJsonPath('tiempo_sin_usar.devuelta', '10:32')
            ->assertJsonPath('tiempo_sin_usar.hora_fin', '12:00')
            ->assertJsonPath('tiempo_sin_usar.sala', 'Logia 04');
    }

    public function test_liberar_el_resto_deja_la_sala_libre_para_otro_grupo(): void
    {
        $reserva = $this->reservaEnUso();
        Carbon::setTestNow('2026-08-14 10:32:40');
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk();

        Carbon::setTestNow('2026-08-14 10:41:00');
        $this->reservarAhora($reserva)->assertStatus(409);

        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertOk()
            ->assertJsonPath('hora_fin', '10:32')
            ->assertJsonPath('hora_fin_reservada', '12:00')
            ->assertJsonPath('estado', 'finalizada');

        $this->reservarAhora($reserva)->assertCreated();
        $this->assertSame(
            'Liberó 79 minutos sin usar de Logia 04, 14/08/2026 de 10:00 a 12:00: la llave volvió a las 10:32',
            Bitacora::where('accion', 'salas.liberar_resto')->sole()->descripcion,
        );
    }

    public function test_el_detalle_del_dia_y_el_listado_ofrecen_liberar_despues(): void
    {
        $reserva = $this->reservaEnUso();
        Carbon::setTestNow('2026-08-14 10:20:00');
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk();

        // Más tarde, con el aviso ya cerrado, el detalle todavía lo ofrece.
        Carbon::setTestNow('2026-08-14 10:50:00');
        $logia = $this->logiaDelDia($reserva);
        $this->assertFalse($logia['libre_ahora']);
        $this->assertSame(70, $logia['tramos'][0]['tiempo_sin_usar']['minutos']);
        $this->assertSame('10:20', $logia['tramos'][0]['tiempo_sin_usar']['devuelta']);
        $this->getJson('/api/reservas?vista=todas')->assertOk()->assertJsonPath('data.0.tiempo_sin_usar.minutos', 70);

        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertOk();

        $logia = $this->logiaDelDia($reserva);
        $this->assertTrue($logia['libre_ahora']);
        $this->assertNull($logia['tramos'][0]['tiempo_sin_usar']);
        $this->assertSame('10:20', $logia['tramos'][0]['hora_fin']);
    }

    /** La sala de la reserva en la vista del día de Salas. */
    private function logiaDelDia(Reserva $reserva): array
    {
        return collect($this->getJson('/api/salas?fecha=2026-08-14')->assertOk()->json('salas'))->firstWhere('id', $reserva->sala_id);
    }

    public function test_el_escaneo_de_la_llave_tambien_ofrece_liberar(): void
    {
        $this->reservaEnUso();
        Carbon::setTestNow('2026-08-14 11:00:00');

        $this->postJson('/api/salas/scan-logia', ['codigo_barras' => 'LOGIA-04'])->assertOk()
            ->assertJsonPath('tiempo_sin_usar.minutos', 60);
    }

    public function test_no_se_ofrece_ni_se_libera_sin_tiempo_que_valga_la_pena(): void
    {
        $reserva = $this->reservaEnUso();

        Carbon::setTestNow('2026-08-14 11:20:00');
        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertStatus(409);

        Carbon::setTestNow('2026-08-14 11:57:00');
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk()->assertJsonPath('tiempo_sin_usar', null);
        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertStatus(409);

        $this->assertSame('12:00:00', $reserva->fresh()->hora_fin);
    }

    public function test_no_se_libera_dos_veces(): void
    {
        $reserva = $this->reservaEnUso();
        Carbon::setTestNow('2026-08-14 10:45:00');
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk();
        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertOk();

        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertStatus(409);
        $this->assertSame('12:00:00', $reserva->fresh()->hora_fin_reservada);
    }
}
