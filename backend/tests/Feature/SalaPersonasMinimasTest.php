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
 * Cuántas personas lleva una reserva de sala (config/salas.php): desde el portal, de 3 a 5;
 * en el mesón, el personal puede anotar de 1 a 7.
 */
class SalaPersonasMinimasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // TestCase lo baja a 2 para las demás pruebas de salas: acá van los valores reales.
        $salas = require config_path('salas.php');
        config(['salas' => $salas]);
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

    public function test_los_rangos_son_3_a_5_en_el_portal_y_1_a_7_en_el_meson(): void
    {
        $this->assertSame([3, 5], Sala::personasDelPortal());
        $this->assertSame([1, 7], Sala::personasDelMeson());
    }

    public function test_el_meson_reserva_de_1_a_7_personas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ruts = Usuario::factory()->count(8)->create()->pluck('rut')->all();

        $this->postJson('/api/reservas', $this->reserva(array_slice($ruts, 0, 8)))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'La reserva admite como máximo 7 personas.');
        $this->postJson('/api/reservas', $this->reserva(array_slice($ruts, 0, 7)))->assertCreated();

        // Otro horario, para no chocar con la de 7: una sola persona también vale.
        $this->postJson('/api/reservas', ['hora_inicio' => '14:00', 'hora_fin' => '15:00'] + $this->reserva([$ruts[7]]))->assertCreated();

        $salas = $this->getJson('/api/salas?fecha=2026-07-10')->assertOk();
        $this->assertSame([1, 7], [$salas->json('personas_minimas'), $salas->json('personas_maximas')]);
    }

    public function test_el_portal_sigue_de_3_a_5(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        $grupo = Usuario::factory()->count(6)->create();
        Sanctum::actingAs($grupo[0]);

        $this->postJson('/api/mi/reservas', $this->reserva($grupo->take(2)->pluck('rut')->all()))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'La reserva necesita al menos 3 personas.');
        $this->postJson('/api/mi/reservas', $this->reserva($grupo->pluck('rut')->all()))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'La reserva admite como máximo 5 personas.');

        $salas = $this->getJson('/api/mi/salas')->assertOk();
        $this->assertSame([3, 5], [$salas->json('personas_minimas'), $salas->json('personas_maximas')]);
    }

    public function test_en_el_meson_los_integrantes_van_de_1_a_7(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $grupo = Usuario::factory()->count(8)->create();
        $reserva = Reserva::factory()->conParticipantes($grupo->take(3))->create([
            'fecha' => now()->addDay()->toDateString(), 'usuario_id' => $grupo[0]->id, 'rut_usuario' => $grupo[0]->rut, 'cantidad_personas' => 3,
        ]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => $grupo->pluck('rut')->all()])
            ->assertStatus(422)
            ->assertJsonPath('errors.ruts.0', 'La reserva admite como máximo 7 personas.');
        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => $grupo->take(7)->pluck('rut')->all()])->assertOk();
        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$grupo[0]->rut]])->assertOk();

        $this->assertSame(1, $reserva->refresh()->cantidad_personas);
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
