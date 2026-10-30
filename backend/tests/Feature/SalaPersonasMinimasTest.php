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

/** Las salas se reservan para 3 personas o más (config/salas.php, personas_minimas). */
class SalaPersonasMinimasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // TestCase lo baja a 2 para las demás pruebas de salas: acá va el valor real.
        config(['salas.personas_minimas' => (require config_path('salas.php'))['personas_minimas']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reserva(array $ruts): array
    {
        return [
            'sala_id' => Sala::where('codigo', 'L02')->value('id'),
            'fecha' => '2026-07-10',
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => count($ruts),
            'ruts' => $ruts,
        ];
    }

    public function test_el_minimo_es_3(): void
    {
        $this->assertSame(3, config('salas.personas_minimas'));
    }

    public function test_el_meson_no_reserva_para_2_y_si_para_3(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ruts = Usuario::factory()->count(3)->create()->pluck('rut')->all();

        $this->postJson('/api/reservas', $this->reserva(array_slice($ruts, 0, 2)))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'La reserva necesita al menos 3 personas.');
        $this->postJson('/api/reservas', $this->reserva($ruts))->assertCreated();
    }

    public function test_el_portal_no_reserva_para_2(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        [$yo, $otro] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $this->postJson('/api/mi/reservas', $this->reserva([$yo->rut, $otro->rut]))->assertStatus(422);
        $this->assertSame(3, $this->getJson('/api/mi/salas')->assertOk()->json('personas_minimas'));
    }

    public function test_no_se_deja_una_reserva_con_menos_de_3_integrantes(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $grupo = Usuario::factory()->count(3)->create();
        $reserva = Reserva::factory()->conParticipantes($grupo)->create([
            'fecha' => now()->addDay()->toDateString(), 'usuario_id' => $grupo[0]->id, 'rut_usuario' => $grupo[0]->rut, 'cantidad_personas' => 3,
        ]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$grupo[0]->rut, $grupo[1]->rut]])
            ->assertStatus(422)
            ->assertJsonPath('errors.ruts.0', 'La reserva necesita al menos 3 personas.');
    }

    public function test_si_un_integrante_se_sale_y_quedan_menos_de_3_la_reserva_se_cancela(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        $grupo = Usuario::factory()->count(3)->create();
        $reserva = Reserva::factory()->conParticipantes($grupo)->create([
            'fecha' => '2026-07-10', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'usuario_id' => $grupo[0]->id, 'rut_usuario' => $grupo[0]->rut, 'cantidad_personas' => 3,
        ]);
        Sanctum::actingAs($grupo[2]);

        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertOk()->assertJsonPath('cancelada', true);

        $this->assertSame('cancelada', $reserva->refresh()->estado);
    }

    public function test_ninguna_sala_dice_menos_de_3_personas(): void
    {
        $this->assertSame(0, Sala::where('capacidad', '<', 3)->count());
    }
}
