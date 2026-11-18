<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Services\ReservaSalaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalaAdministracionSinVencimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reserva(Sala $sala, array $datos = []): Reserva
    {
        return Reserva::factory()->create([
            'sala_id' => $sala->id, 'fecha' => '2026-11-18',
            'hora_inicio' => '10:00', 'hora_fin' => '12:00', ...$datos,
        ]);
    }

    public static function salasAdministrativas(): iterable
    {
        foreach (['SM1', 'SM2', 'MOR', 'COO', 'L09', 'ATEC'] as $codigo) {
            yield $codigo => [$codigo];
        }
    }

    #[DataProvider('salasAdministrativas')]
    public function test_sigue_en_calendario_y_listado_y_permite_confirmar_tarde(string $codigo): void
    {
        $sala = Sala::where('codigo', $codigo)->sole();
        $this->assertTrue($sala->solo_administracion);
        $reserva = $this->reserva($sala);

        Carbon::setTestNow('2026-11-18 11:00:00');
        $this->assertNull($reserva->plazoConfirmacion());
        $this->assertFalse($reserva->estaVencidaSinConfirmar());

        $calendario = $this->getJson('/api/salas?fecha=2026-11-18')->assertOk();
        $tramo = collect($calendario->json('salas'))->firstWhere('id', $sala->id)['tramos'][0];
        $this->assertSame($reserva->id, $tramo['reserva_id']);
        $this->assertNull($tramo['plazo_confirmacion']);
        $this->assertFalse($tramo['vencida_sin_confirmar']);

        $this->getJson('/api/reservas?desde=2026-11-18&hasta=2026-11-18')->assertOk()
            ->assertJsonPath('data.0.id', $reserva->id)
            ->assertJsonPath('data.0.situacion', 'pendiente')
            ->assertJsonPath('data.0.plazo_confirmacion', null);

        $reporte = '/api/reportes/resumen?tab=logias&periodo=mes&fecha=2026-11-18';
        $this->getJson($reporte)->assertOk()->assertJsonPath('total', 0);
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();
        $this->getJson($reporte)->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('activa', $reserva->fresh()->estado);
        $this->assertNotNull($reserva->fresh()->hora_prestamo_real);
    }

    public function test_sin_confirmar_sigue_bloqueando_el_horario_y_no_se_libera_por_inasistencia(): void
    {
        $sala = Sala::factory()->create(['solo_administracion' => true]);
        $reserva = $this->reserva($sala);
        Carbon::setTestNow('2026-11-18 10:30:00');

        $servicio = app(ReservaSalaService::class);
        $this->assertTrue($servicio->existeSolapamiento($sala->id, '2026-11-18', '11:00', '12:00'));
        $this->assertSame(0, $servicio->duracionMaximaDisponible($sala->id, '2026-11-18', '10:30'));
        $this->patchJson("/api/reservas/{$reserva->id}/liberar")->assertStatus(409);
        $this->assertSame('activa', $reserva->fresh()->estado);

        $this->deleteJson("/api/reservas/{$reserva->id}")->assertNoContent();
        $this->assertSame('cancelada', $reserva->fresh()->estado);
    }

    public function test_la_revision_nocturna_conserva_administracion_y_libera_logias_normales(): void
    {
        $administrativa = $this->reserva(Sala::factory()->create(['solo_administracion' => true]));
        $logia = $this->reserva(Sala::factory()->create(['solo_administracion' => false]));
        Carbon::setTestNow('2026-11-19 00:10:00');

        $this->artisan('salas:liberar-vencidas')->expectsOutputToContain('1 reserva(s)')->assertSuccessful();
        $this->assertSame('activa', $administrativa->fresh()->estado);
        $this->assertSame('no_show', $logia->fresh()->estado);
    }

    public function test_las_series_y_las_reservas_existentes_usan_la_categoria_de_la_sala(): void
    {
        $sala = Sala::factory()->create(['solo_administracion' => false]);
        $reserva = $this->reserva($sala, ['serie' => '66a10518-95a0-40ac-9426-0e95b98ba113']);
        $sala->update(['solo_administracion' => true]);
        Carbon::setTestNow('2026-11-18 10:30:00');

        $this->assertFalse(app(ReservaSalaService::class)->liberarSiVencida($reserva->fresh()));
        $this->assertNull($reserva->fresh()->plazoConfirmacion());
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();
    }

    public function test_las_logias_normales_mantienen_el_plazo_de_quince_minutos(): void
    {
        $reserva = $this->reserva(Sala::factory()->create(['solo_administracion' => false]));
        Carbon::setTestNow('2026-11-18 10:16:00');

        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertStatus(409);
        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_el_codigo_de_la_sala_permite_confirmar_despues_del_plazo_normal(): void
    {
        $sala = Sala::where('codigo', 'SM1')->sole();
        $reserva = $this->reserva($sala);
        Carbon::setTestNow('2026-11-18 11:00:00');

        $this->getJson('/api/salas?fecha=2026-11-18')->assertOk();
        $this->postJson('/api/salas/llave', ['codigo' => $sala->codigo])->assertOk()->assertJsonPath('id', $reserva->id);
        $this->assertNotNull($reserva->fresh()->hora_prestamo_real);
    }

    public function test_sin_vencimiento_no_permite_confirmar_la_llegada_de_otro_dia(): void
    {
        $reserva = $this->reserva(Sala::where('codigo', 'SM1')->sole(), ['fecha' => '2026-11-19']);

        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertStatus(409);
        $this->assertNull($reserva->fresh()->hora_prestamo_real);
        $this->assertSame('activa', $reserva->fresh()->estado);
    }
}
