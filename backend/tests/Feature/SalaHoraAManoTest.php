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
 * no solo a la hora o a la media hora. En el portal, el estudiante también.
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

    public function test_en_el_portal_el_estudiante_tambien_escribe_la_hora_a_mano(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        [$yo, $otro, $tercera] = Usuario::factory()->count(3)->create();
        Sanctum::actingAs($yo);

        $this->reservar('/api/mi/reservas', Sala::factory()->create(), [$yo->rut, $otro->rut, $tercera->rut], ['hora_inicio' => '17:10', 'hora_fin' => '18:35'])
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '17:10:00')
            ->assertJsonPath('hora_fin', '18:35:00');
    }

    public function test_en_el_portal_las_horas_a_mano_no_chocan_con_otra_reserva(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        $sala = Sala::factory()->create();
        [$a1, $a2, $a3, $b1, $b2, $b3] = Usuario::factory()->count(6)->create();

        Sanctum::actingAs($a1);
        $this->reservar('/api/mi/reservas', $sala, [$a1->rut, $a2->rut, $a3->rut], ['hora_inicio' => '17:10', 'hora_fin' => '18:00'])->assertCreated();

        // Otro grupo: se monta con la anterior, no; justo cuando termina, sí.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($b1);
        $this->reservar('/api/mi/reservas', $sala, [$b1->rut, $b2->rut, $b3->rut], ['hora_inicio' => '17:45', 'hora_fin' => '18:30'])->assertStatus(409);
        $this->reservar('/api/mi/reservas', $sala, [$b1->rut, $b2->rut, $b3->rut], ['hora_inicio' => '16:30', 'hora_fin' => '17:15'])->assertStatus(409);
        $this->reservar('/api/mi/reservas', $sala, [$b1->rut, $b2->rut, $b3->rut], ['hora_inicio' => '18:00', 'hora_fin' => '18:40'])->assertCreated();
    }

    public function test_en_el_portal_la_hora_a_mano_sigue_las_reglas_del_portal(): void
    {
        Carbon::setTestNow('2026-07-10 16:27:00');
        [$yo, $otro, $tercera] = Usuario::factory()->count(3)->create();
        Sanctum::actingAs($yo);
        $sala = Sala::factory()->create();
        $grupo = [$yo->rut, $otro->rut, $tercera->rut];

        // Una hora que ya pasó, o más de 2 horas, no.
        $this->reservar('/api/mi/reservas', $sala, $grupo, ['hora_inicio' => '16:20', 'hora_fin' => '17:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esa hora ya pasó — elige un horario desde ahora en adelante.');
        $this->reservar('/api/mi/reservas', $sala, $grupo, ['hora_inicio' => '16:40', 'hora_fin' => '18:45'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar entre 30 minutos y 2 horas.');
    }
}
