<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reservar desde ahora en el mesón: el grupo ya está ahí, así que la reserva parte en ese
 * minuto (no a la hora o a la media hora), dura hasta 2 horas y queda con la llegada
 * confirmada, sin el paso de «Confirmar llegada».
 */
class SalaReservaAhoraTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservarAhora(Sala $sala, array $ruts, array $extra = [])
    {
        return $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => '16:27',
            'hora_fin' => '18:27',
            'cantidad_personas' => count($ruts),
            'ruts' => $ruts,
            'inmediata' => true,
            'duracion' => 120,
            ...$extra,
        ]);
    }

    public function test_a_las_1627_se_reserva_hasta_las_1827_con_la_llegada_confirmada(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);
        Carbon::setTestNow('2026-07-10 16:27:40'); // viernes
        $sala = Sala::factory()->create();
        $grupo = Usuario::factory()->count(2)->create();

        $this->reservarAhora($sala, $grupo->pluck('rut')->all())
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '16:27:00')
            ->assertJsonPath('hora_fin', '18:27:00')
            ->assertJsonPath('prestado_por_staff_id', $staff->id)
            ->assertJsonCount(2, 'asistencia.registradas');

        // Los dos quedan presentes en la biblioteca, como al confirmar la llegada.
        $this->assertSame(2, Entrada::where('via', 'sala')->count());
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.llegada']);
    }

    public function test_el_termino_lo_calcula_el_servidor_aunque_el_navegador_vaya_corrido(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 16:27:05');
        $sala = Sala::factory()->create();

        // El navegador cree que son las 16:26: con su término la reserva duraría 29 minutos.
        $this->reservarAhora($sala, [Usuario::factory()->create()->rut], ['hora_inicio' => '16:26', 'hora_fin' => '16:56', 'duracion' => 30])
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '16:27:00')
            ->assertJsonPath('hora_fin', '16:57:00');
    }

    public function test_no_pasa_de_2_horas(): void
    {
        // Sin el permiso de reservas largas (salas.sin_limite): rige el máximo de 2 horas.
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        Carbon::setTestNow('2026-07-10 16:27:00');

        $this->reservarAhora(Sala::factory()->create(), [Usuario::factory()->create()->rut], ['duracion' => 121])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar entre 30 minutos y 2 horas.');
    }

    public function test_desde_ahora_es_solo_para_hoy(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-09 16:27:00');

        $this->reservarAhora(Sala::factory()->create(), [Usuario::factory()->create()->rut])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Una reserva desde ahora es para hoy: para otro día, elige la hora de inicio.');
    }

    public function test_cerca_del_cierre_no_se_reserva_por_2_horas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 20:00:00'); // viernes: cierra a las 20:30
        $sala = Sala::factory()->create();
        $ruts = [Usuario::factory()->create()->rut];

        $this->reservarAhora($sala, $ruts, ['duracion' => 120])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La biblioteca cierra a las 20:30: desde ahora se puede reservar hasta esa hora (30 minutos).');
        // Con la hora de término escrita a mano, igual.
        $this->reservarAhora($sala, $ruts, ['hora_fin' => '21:00', 'duracion' => null])->assertStatus(422);

        $this->reservarAhora($sala, $ruts, ['duracion' => 30])->assertCreated()->assertJsonPath('hora_fin', '20:30:00');
    }

    public function test_si_falta_menos_que_lo_minimo_para_el_cierre_no_se_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 20:05:00');

        $this->reservarAhora(Sala::factory()->create(), [Usuario::factory()->create()->rut], ['duracion' => 30])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La biblioteca cierra a las 20:30: desde ahora ya no alcanza una reserva de al menos 30 minutos.');
    }

    public function test_el_sabado_cuenta_el_cierre_de_las_1400(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-11 13:00:00'); // sábado

        $this->reservarAhora(Sala::factory()->create(), [Usuario::factory()->create()->rut], ['fecha' => '2026-07-11', 'duracion' => 120])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La biblioteca cierra a las 14:00: desde ahora se puede reservar hasta esa hora (60 minutos).');
    }

    public function test_una_reserva_con_hora_sigue_esperando_la_llegada(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 16:27:00');

        $this->reservarAhora(Sala::factory()->create(), [Usuario::factory()->create()->rut], [
            'hora_inicio' => '17:00', 'hora_fin' => '18:00', 'inmediata' => false, 'duracion' => null,
        ])->assertCreated()->assertJsonPath('hora_prestamo_real', null);

        $this->assertSame(0, Entrada::count());
    }
}
