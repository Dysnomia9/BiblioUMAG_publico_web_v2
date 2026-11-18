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

class ReporteSalasUsoConfirmadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:05:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_serie_de_tres_semanas_no_cuenta_como_uso_hasta_confirmar_y_cancelar_futuras_conserva_el_uso(): void
    {
        $sala = Sala::factory()->create(['codigo' => 'QA1']);
        $personas = Usuario::factory()->count(2)->create();
        $serie = '11111111-1111-4111-8111-111111111111';
        $base = ['sala_id' => $sala->id, 'usuario_id' => $personas[0]->id, 'serie' => $serie,
            'hora_inicio' => '10:00', 'hora_fin' => '12:00'];
        $hoy = Reserva::factory()->conParticipantes($personas)->create([...$base, 'fecha' => '2026-11-18']);
        for ($dia = 1; $dia <= 21; $dia++) {
            Reserva::factory()->conParticipantes($personas)->create([...$base, 'fecha' => now()->addDays($dia)->toDateString()]);
        }
        $url = '/api/reportes/resumen?tab=logias&periodo=semestre&fecha=2026-11-18';
        $antes = $this->getJson($url)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('personas', 0)->assertJsonPath('porSala', []);
        $this->assertSame(0, collect($antes->json('serie'))->sum('value'));
        $this->assertSame(22, $antes->json('porSalaDetalle.0.pendientes'));

        $this->patchJson("/api/reservas/{$hoy->id}/llegada")->assertOk();
        $usada = $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('personas', 2)
            ->assertJsonPath('porSala.0.value', 1)->assertJsonPath('porSalaDetalle.0.reservas', 22)
            ->assertJsonPath('porSalaDetalle.0.presentes', 1)->assertJsonPath('porSalaDetalle.0.pendientes', 21)
            ->assertJsonPath('porSalaDetalle.0.no_llegaron', 0);
        $this->assertSame(1, collect($usada->json('serie'))->sum('value'));
        $this->assertSame(2, collect($usada->json('porTipoUsuario'))->sum('value'));

        $this->deleteJson("/api/reservas/serie/{$serie}")->assertOk()->assertJsonPath('canceladas', 21);
        $despues = $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('personas', 2)
            ->assertJsonPath('porSalaDetalle.0.reservas', 1)->assertJsonPath('porSalaDetalle.0.presentes', 1)
            ->assertJsonPath('porSalaDetalle.0.pendientes', 0);
        $this->assertSame($usada->json('porSala'), $despues->json('porSala'));
        $this->assertNotNull($hoy->fresh()->hora_prestamo_real);
    }

    public function test_el_ranking_y_la_comparacion_ignoran_pendientes_inasistencias_y_canceladas_sin_uso(): void
    {
        $usada = Sala::factory()->create(['codigo' => 'USO']);
        $soloReservada = Sala::factory()->create(['codigo' => 'PLAN']);
        Reserva::factory()->create(['sala_id' => $usada->id, 'fecha' => '2026-11-18', 'hora_prestamo_real' => '2026-11-18 10:01']);
        Reserva::factory()->create(['sala_id' => $usada->id, 'fecha' => '2026-10-12', 'hora_prestamo_real' => '2026-10-12 10:01', 'estado' => 'finalizada']);
        Reserva::factory()->count(4)->create(['sala_id' => $soloReservada->id, 'fecha' => '2026-11-20']);
        Reserva::factory()->create(['sala_id' => $soloReservada->id, 'fecha' => '2026-11-17', 'estado' => 'no_show']);
        Reserva::factory()->create(['sala_id' => $soloReservada->id, 'fecha' => '2026-11-19', 'estado' => 'cancelada']);
        Reserva::factory()->create(['sala_id' => $soloReservada->id, 'fecha' => '2026-10-10']);
        $r = $this->getJson('/api/reportes/resumen?tab=logias&periodo=mes&fecha=2026-11-18')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('totalAnterior', 1)->assertJsonCount(1, 'porSala');
        $this->assertStringStartsWith('USO · ', $r->json('porSala.0.label'));
        $detalle = collect($r->json('porSalaDetalle'))->firstWhere('codigo', 'PLAN');
        $this->assertSame(5, $detalle['reservas']);
        $this->assertSame(0, $detalle['presentes']);
        $this->assertSame(4, $detalle['pendientes']);
        $this->assertSame(1, $detalle['no_llegaron']);
        $this->assertSame(0, $detalle['minutos_uso']);
    }

    public function test_cancelar_una_reserva_despues_de_su_asistencia_no_borra_el_uso_confirmado(): void
    {
        $reserva = Reserva::factory()->create(['fecha' => '2026-11-18', 'hora_prestamo_real' => '2026-11-18 10:01']);
        $this->deleteJson("/api/reservas/{$reserva->id}")->assertNoContent();
        $this->getJson('/api/reportes/resumen?tab=logias&periodo=dia&fecha=2026-11-18')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('porSala.0.value', 1);
    }
}
