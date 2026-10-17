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
 * Cada persona tiene a lo más una reserva de sala pendiente (activa y sin llegada
 * confirmada), sea del día que sea: para pedir otra, primero usa o cancela la que
 * tiene. Vale en el mesón, en el portal y al sumar integrantes a una reserva.
 */
class ReservaPendienteUnicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 14:05:00'); // viernes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<Usuario>  $integrantes */
    private function reserva(Sala $sala, array $integrantes, array $atributos = []): Reserva
    {
        return Reserva::factory()->conParticipantes($integrantes)->create([
            'sala_id' => $sala->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '16:00',
            'hora_fin' => '17:00',
            'usuario_id' => $integrantes[0]->id,
            'rut_usuario' => $integrantes[0]->rut,
            'cantidad_personas' => count($integrantes),
            ...$atributos,
        ]);
    }

    private function reservarEnMeson(Sala $sala, array $integrantes, string $inicio = '18:00', string $fin = '19:00')
    {
        return $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'cantidad_personas' => count($integrantes),
            'ruts' => collect($integrantes)->pluck('rut')->all(),
        ]);
    }

    public function test_quien_ya_tiene_una_reserva_pendiente_no_entra_en_otra(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $this->reserva($logia1, [$ana, $beto], ['fecha' => '2026-08-17']);

        // Beto viene como integrante, no a cargo: igual cuenta. Y la pendiente es de otro día.
        $this->reservarEnMeson($logia2, [$carla, $beto])
            ->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$beto->rut} ya tiene una reserva pendiente ({$logia1->nombre}, 17/08/2026 de 16:00 a 17:00): debe usarla o cancelarla antes de reservar otra sala.");

        $this->assertSame(1, Reserva::count());
    }

    public function test_una_vez_que_el_grupo_llego_o_la_reserva_termino_se_puede_pedir_otra(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto, $carla, $dani, $eli, $fran] = Usuario::factory()->count(6)->create();
        $this->reserva($logia1, [$ana, $beto], ['hora_inicio' => '14:00', 'hora_fin' => '15:00', 'hora_prestamo_real' => '2026-08-14 14:01:00']);
        $this->reserva($logia1, [$carla, $dani], ['estado' => 'cancelada']);
        // Nadie llegó en el plazo: se libera sola y deja de contar.
        $this->reserva($logia1, [$eli, $fran], ['hora_inicio' => '13:00', 'hora_fin' => '14:00', 'created_at' => '2026-08-14 09:00:00']);

        $this->reservarEnMeson($logia2, [$ana, $beto])->assertCreated();
        $this->reservarEnMeson($logia2, [$carla, $dani], '16:00', '17:00')->assertCreated();
        $this->reservarEnMeson($logia1, [$eli, $fran], '17:00', '18:00')->assertCreated();
    }

    public function test_en_el_portal_se_le_dice_a_la_propia_persona(): void
    {
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $this->reserva($logia1, [$ana, $beto]);
        Sanctum::actingAs($ana);

        $this->postJson('/api/mi/reservas', [
            'sala_id' => $logia2->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '18:00',
            'hora_fin' => '19:00',
            'cantidad_personas' => 2,
            'ruts' => [$ana->rut, $carla->rut],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', "Ya tienes una reserva pendiente ({$logia1->nombre}, 14/08/2026 de 16:00 a 17:00): úsala o cancélala antes de reservar otra sala.");
    }

    public function test_en_el_portal_no_se_revela_donde_esta_la_reserva_de_otra_persona(): void
    {
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $this->reserva($logia1, [$beto, $carla]);
        Sanctum::actingAs($ana);

        $respuesta = $this->postJson('/api/mi/reservas', [
            'sala_id' => $logia2->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '18:00',
            'hora_fin' => '19:00',
            'cantidad_personas' => 2,
            'ruts' => [$ana->rut, $beto->rut],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$beto->rut} ya tiene una reserva pendiente: debe usarla o cancelarla antes de reservar otra sala.");

        $this->assertStringNotContainsString($logia1->nombre, $respuesta->content());
    }

    public function test_no_se_suma_a_una_reserva_pendiente_quien_ya_tiene_otra(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto, $carla, $dani] = Usuario::factory()->count(4)->create();
        $pendiente = $this->reserva($logia1, [$ana, $beto]);
        $this->reserva($logia2, [$carla, $dani], ['fecha' => '2026-08-17']);

        $this->putJson("/api/reservas/{$pendiente->id}/integrantes", ['ruts' => [$ana->rut, $beto->rut, $carla->rut]])
            ->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$carla->rut} ya tiene una reserva pendiente ({$logia2->nombre}, 17/08/2026 de 16:00 a 17:00): debe usarla o cancelarla antes de reservar otra sala.");

        // Con el grupo ya en la sala, esta deja de ser pendiente: sumarla no le da dos.
        $enUso = $this->reserva($logia1, [$ana, $beto], ['hora_inicio' => '14:00', 'hora_fin' => '15:00', 'hora_prestamo_real' => '2026-08-14 14:01:00']);
        $pendiente->update(['estado' => 'cancelada']);
        $this->putJson("/api/reservas/{$enUso->id}/integrantes", ['ruts' => [$ana->rut, $beto->rut, $carla->rut]])->assertOk();
    }
}
