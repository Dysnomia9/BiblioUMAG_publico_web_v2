<?php

namespace Tests\Feature;

use App\Models\PestanaSala;
use App\Models\Sala;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * En las salas de una pestaña que admite personas no registradas (HCM), el mesón reserva
 * también con el nombre de la persona a cargo: académicos o docentes que no están en la base.
 */
class ReservaSinRegistroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservarPorNombre(string $codigo)
    {
        return $this->postJson('/api/reservas', [
            'sala_id' => Sala::where('codigo', $codigo)->sole()->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 3,
            'modo_responsable' => 'actividad',
            'nombre_actividad' => 'Dra. Ana Soto',
        ]);
    }

    public function test_hcm_admite_reservar_con_el_nombre_de_quien_no_esta_registrado(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->assertTrue(PestanaSala::where('nombre', 'HCM')->sole()->admite_sin_registro);

        $this->reservarPorNombre('HCM1')->assertCreated()
            ->assertJsonPath('usuario_id', null)
            ->assertJsonPath('nombre_actividad', 'Dra. Ana Soto');

        $sala = collect($this->getJson('/api/salas?fecha=2026-07-10')->json('salas'))->firstWhere('codigo', 'HCM1');
        $this->assertTrue($sala['admite_nombre_libre']);
    }

    public function test_las_logias_de_la_biblioteca_siguen_pidiendo_rut(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->reservarPorNombre('L01')->assertStatus(422);
    }

    public function test_al_desmarcar_la_pestana_hcm_vuelve_a_pedir_rut(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $hcm = PestanaSala::where('nombre', 'HCM')->sole();

        $this->putJson("/api/pestanas-salas/{$hcm->id}", ['admite_sin_registro' => false])->assertOk();

        $this->reservarPorNombre('HCM1')->assertStatus(422);
    }
}
