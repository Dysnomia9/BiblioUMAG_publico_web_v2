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

/**
 * En el mesón la hora de inicio y la de término se pueden escribir a mano (de 17:10 a 18:35),
 * no solo a la hora o a la media hora. El portal no cambia.
 */
class SalaHoraAManoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservar(string $url, Sala $sala, array $ruts, array $tramo)
    {
        return $this->postJson($url, [
            'sala_id' => $sala->id,
            'fecha' => '2026-07-10',
            'cantidad_personas' => count($ruts),
            'ruts' => $ruts,
            ...$tramo,
        ]);
    }

    public function test_el_meson_reserva_de_1700_a_1835(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 16:00:00');

        $this->reservar('/api/reservas', Sala::factory()->create(), [Usuario::factory()->create()->rut], ['hora_inicio' => '17:00', 'hora_fin' => '18:35'])
            ->assertCreated()
            ->assertJsonPath('hora_fin', '18:35:00')
            ->assertJsonPath('hora_prestamo_real', null);
    }

    public function test_el_meson_reserva_de_1710_a_1835(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 16:00:00');

        $this->reservar('/api/reservas', Sala::factory()->create(), [Usuario::factory()->create()->rut], ['hora_inicio' => '17:10', 'hora_fin' => '18:35'])
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '17:10:00')
            ->assertJsonPath('hora_fin', '18:35:00')
            // Con hora de inicio, el grupo igual confirma su llegada.
            ->assertJsonPath('hora_prestamo_real', null);
    }

    public function test_una_hora_de_inicio_escrita_que_ya_paso_no_sirve(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'asistente']));
        Carbon::setTestNow('2026-07-10 16:27:00');

        $this->reservar('/api/reservas', Sala::factory()->create(), [Usuario::factory()->create()->rut], ['hora_inicio' => '16:20', 'hora_fin' => '17:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esa hora ya pasó — elige un horario desde ahora en adelante.');
    }

    public function test_desde_ahora_con_la_hora_de_termino_escrita(): void
    {
        // Sin el permiso de reservas largas (salas.sin_limite): rige el máximo de 2 horas.
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        Carbon::setTestNow('2026-07-10 16:27:30');
        $sala = Sala::factory()->create();
        $ruts = [Usuario::factory()->create()->rut];

        // 16:27 a 18:35 son 2 h 8 min: pasa del máximo.
        $this->reservar('/api/reservas', $sala, $ruts, ['hora_inicio' => '16:27', 'hora_fin' => '18:35', 'inmediata' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar entre 30 minutos y 2 horas.');

        $this->reservar('/api/reservas', $sala, $ruts, ['hora_inicio' => '16:27', 'hora_fin' => '18:20', 'inmediata' => true])
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '16:27:00')
            ->assertJsonPath('hora_fin', '18:20:00')
            ->assertJsonPath('asistencia.registradas.0', fn ($nombre) => is_string($nombre));
    }

    public function test_no_choca_con_la_siguiente_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-10 16:00:00');
        $sala = Sala::factory()->create();
        Reserva::factory()->conParticipantes(Usuario::factory()->count(2)->create())->create([
            'sala_id' => $sala->id, 'fecha' => '2026-07-10', 'hora_inicio' => '18:30', 'hora_fin' => '19:30', 'cantidad_personas' => 2,
        ]);

        $this->reservar('/api/reservas', $sala, [Usuario::factory()->create()->rut], ['hora_inicio' => '17:00', 'hora_fin' => '18:35'])
            ->assertStatus(409);
    }

    public function test_el_portal_sigue_en_la_grilla(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        [$yo, $otro] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $sala = Sala::factory()->create();
        foreach ([['17:00', '18:35'], ['17:10', '18:00']] as [$inicio, $fin]) {
            $this->reservar('/api/mi/reservas', $sala, [$yo->rut, $otro->rut], ['hora_inicio' => $inicio, 'hora_fin' => $fin])
                ->assertStatus(422)
                ->assertJsonPath('message', 'El inicio y el término deben caer en una hora en punto o media.');
        }
    }
}
