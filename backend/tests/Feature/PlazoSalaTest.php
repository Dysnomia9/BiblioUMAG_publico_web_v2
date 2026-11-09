<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\ConfiguracionInstitucional;
use App\Models\PeriodoPlazoSala;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\ReservaSalaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlazoSalaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Un viernes con atención, para que ninguna prueba dependa del día en que corre.
        Carbon::setTestNow('2026-11-13 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservaDe(string $fecha = '2026-11-13', string $inicio = '14:00', string $fin = '16:00'): Reserva
    {
        return Reserva::factory()->create(['fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin]);
    }

    public function test_de_fabrica_una_reserva_tiene_15_minutos(): void
    {
        $reserva = $this->reservaDe();

        $this->assertSame(15, $reserva->plazo_confirmacion_minutos);
        $this->assertSame('2026-11-13 14:15:00', $reserva->plazoConfirmacion()->toDateTimeString());
    }

    public function test_cambiar_el_tiempo_vale_para_las_siguientes_reservas_y_no_para_las_ya_hechas(): void
    {
        $yaHecha = $this->reservaDe();

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->putJson('/api/plazo-salas', ['minutos' => 10])->assertOk()->assertJsonPath('general', 10);

        $siguiente = $this->reservaDe('2026-11-13', '17:00', '18:00');

        $this->assertSame('2026-11-13 14:15:00', $yaHecha->fresh()->plazoConfirmacion()->toDateTimeString());
        $this->assertSame('2026-11-13 17:10:00', $siguiente->plazoConfirmacion()->toDateTimeString());
    }

    public function test_editar_una_reserva_el_mismo_dia_no_le_cambia_su_tiempo(): void
    {
        $reserva = $this->reservaDe();
        ConfiguracionInstitucional::actual()->update(['plazo_confirmacion_sala' => 10]);

        $reserva->fresh()->update(['hora_inicio' => '15:00', 'fecha' => '2026-11-13']);

        $this->assertSame(15, $reserva->fresh()->plazo_confirmacion_minutos);
    }

    public function test_las_reservas_de_las_fechas_de_pruebas_tienen_ese_tiempo_y_las_demas_no(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5, 'motivo' => 'Semana de pruebas'])
            ->assertStatus(201)
            ->assertJsonPath('desde', '2026-11-16')
            ->assertJsonPath('minutos', 5);

        $this->assertSame(15, $this->reservaDe('2026-11-13')->plazo_confirmacion_minutos, 'el día anterior');
        $this->assertSame(5, $this->reservaDe('2026-11-16')->plazo_confirmacion_minutos, 'el primer día');
        $this->assertSame(5, $this->reservaDe('2026-11-20')->plazo_confirmacion_minutos, 'el último día');
        $this->assertSame(15, $this->reservaDe('2026-11-21', '10:00', '11:00')->plazo_confirmacion_minutos, 'el día siguiente');
    }

    public function test_mover_una_reserva_a_otro_dia_toma_el_tiempo_de_ese_dia(): void
    {
        PeriodoPlazoSala::create(['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5]);
        $reserva = $this->reservaDe('2026-11-13');

        $reserva->update(['fecha' => '2026-11-17']);

        $this->assertSame(5, $reserva->fresh()->plazo_confirmacion_minutos);
    }

    public function test_con_menos_tiempo_la_sala_se_libera_antes_y_queda_la_inasistencia(): void
    {
        PeriodoPlazoSala::create(['desde' => '2026-11-13', 'hasta' => '2026-11-13', 'minutos' => 5]);
        $reserva = $this->reservaDe();
        $salas = app(ReservaSalaService::class);

        Carbon::setTestNow('2026-11-13 14:05:00');
        $this->assertFalse($salas->liberarSiVencida($reserva->fresh()), 'justo al cumplirse los 5 minutos todavía alcanza');

        Carbon::setTestNow('2026-11-13 14:06:00');
        $this->assertSame(1, $salas->liberarVencidas());
        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_confirmar_la_llegada_respeta_el_tiempo_de_la_reserva_y_lo_dice_en_el_mensaje(): void
    {
        PeriodoPlazoSala::create(['desde' => '2026-11-13', 'hasta' => '2026-11-13', 'minutos' => 5]);
        $aTiempo = $this->reservaDe();
        $tarde = $this->reservaDe('2026-11-13', '16:00', '18:00');
        $salas = app(ReservaSalaService::class);
        $staff = Staff::factory()->create();

        Carbon::setTestNow('2026-11-13 14:04:00');
        $this->assertNotNull($salas->registrarLlegada($aTiempo->fresh(), $staff)->hora_prestamo_real);

        Carbon::setTestNow('2026-11-13 16:06:00');
        try {
            $salas->registrarLlegada($tarde->fresh(), $staff);
            $this->fail('Debió rechazar la llegada fuera de plazo');
        } catch (\RuntimeException $e) {
            $this->assertSame('El plazo de 5 minutos para confirmar esta reserva ya venció — la sala quedó liberada', $e->getMessage());
        }
        $this->assertSame('no_show', $tarde->fresh()->estado);
    }

    public function test_el_meson_y_el_portal_reciben_el_tiempo_del_dia_que_miran(): void
    {
        PeriodoPlazoSala::create(['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5]);
        Sala::factory()->create();

        Sanctum::actingAs(Staff::factory()->create());
        $this->getJson('/api/salas?fecha=2026-11-13')->assertOk()->assertJsonPath('minutos_para_presentarse', 15);
        $this->getJson('/api/salas?fecha=2026-11-17')->assertOk()->assertJsonPath('minutos_para_presentarse', 5);

        Carbon::setTestNow('2026-11-17 09:00:00');
        Sanctum::actingAs(Usuario::factory()->create());
        $this->getJson('/api/mi/salas')->assertOk()->assertJsonPath('minutos_para_presentarse', 5);
    }

    public function test_valida_los_minutos_y_las_fechas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        foreach ([0, 31, 'quince', null] as $invalido) {
            $this->putJson('/api/plazo-salas', ['minutos' => $invalido])->assertStatus(422)->assertJsonValidationErrors('minutos');
        }
        $this->assertSame(15, ConfiguracionInstitucional::actual()->plazo_confirmacion_sala);

        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-20', 'hasta' => '2026-11-16', 'minutos' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-01', 'hasta' => '2026-11-05', 'minutos' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 31])
            ->assertStatus(422)->assertJsonValidationErrors('minutos');
        $this->assertSame(0, PeriodoPlazoSala::count());
    }

    public function test_no_se_pueden_cruzar_dos_rangos_de_fechas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        PeriodoPlazoSala::create(['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5, 'motivo' => 'Semana de pruebas']);

        foreach ([['2026-11-20', '2026-11-25'], ['2026-11-14', '2026-11-16'], ['2026-11-17', '2026-11-18'], ['2026-11-14', '2026-11-25']] as [$desde, $hasta]) {
            $this->postJson('/api/plazo-salas/periodos', ['desde' => $desde, 'hasta' => $hasta, 'minutos' => 10])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Esas fechas se cruzan con las que ya están fijadas del 16/11/2026 al 20/11/2026 (Semana de pruebas): quítalas primero o elige otras.');
        }

        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-21', 'hasta' => '2026-11-25', 'minutos' => 10])->assertStatus(201);
    }

    public function test_quitar_las_fechas_devuelve_el_tiempo_general_a_las_siguientes(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $periodo = PeriodoPlazoSala::create(['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5]);
        $yaHecha = $this->reservaDe('2026-11-17');

        $this->deleteJson("/api/plazo-salas/periodos/{$periodo->id}")->assertStatus(204);

        $this->assertSame(5, $yaHecha->fresh()->plazo_confirmacion_minutos);
        $this->assertSame(15, $this->reservaDe('2026-11-18')->plazo_confirmacion_minutos);
    }

    public function test_la_pantalla_recibe_el_tiempo_general_y_solo_las_fechas_que_no_han_terminado(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        PeriodoPlazoSala::create(['desde' => '2026-11-01', 'hasta' => '2026-11-05', 'minutos' => 5, 'motivo' => 'Ya pasó']);
        PeriodoPlazoSala::create(['desde' => '2026-11-10', 'hasta' => '2026-11-13', 'minutos' => 7, 'motivo' => 'Termina hoy']);

        $this->getJson('/api/plazo-salas')->assertOk()
            ->assertJsonPath('general', 15)
            ->assertJsonPath('minimo', 1)
            ->assertJsonPath('maximo', 30)
            ->assertJsonCount(1, 'periodos')
            ->assertJsonPath('periodos.0.motivo', 'Termina hoy');
    }

    public function test_solo_quien_gestiona_los_catalogos_cambia_el_tiempo(): void
    {
        $periodo = PeriodoPlazoSala::create(['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5]);
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/plazo-salas')->assertStatus(403);
        $this->putJson('/api/plazo-salas', ['minutos' => 1])->assertStatus(403);
        $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-12-01', 'hasta' => '2026-12-02', 'minutos' => 1])->assertStatus(403);
        $this->deleteJson("/api/plazo-salas/periodos/{$periodo->id}")->assertStatus(403);

        $this->assertSame(15, ConfiguracionInstitucional::actual()->plazo_confirmacion_sala);
        $this->assertSame(1, PeriodoPlazoSala::count());
    }

    public function test_cada_cambio_queda_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create(['nombre' => 'Jefatura']));

        $this->putJson('/api/plazo-salas', ['minutos' => 10])->assertOk();
        $id = $this->postJson('/api/plazo-salas/periodos', ['desde' => '2026-11-16', 'hasta' => '2026-11-20', 'minutos' => 5, 'motivo' => 'Semana de pruebas'])->json('id');
        $this->deleteJson("/api/plazo-salas/periodos/{$id}")->assertStatus(204);

        $this->assertSame('Cambió el tiempo para presentarse a una sala de 15 a 10 minutos', Bitacora::where('accion', 'configuracion.plazo_salas')->value('descripcion'));
        $this->assertSame('Fijó 5 minutos para presentarse a una sala del 16/11/2026 al 20/11/2026 (Semana de pruebas)', Bitacora::where('accion', 'configuracion.crear_periodo_plazo_sala')->value('descripcion'));
        $this->assertSame('Quitó el tiempo especial para presentarse a una sala del 16/11/2026 al 20/11/2026 (Semana de pruebas)', Bitacora::where('accion', 'configuracion.eliminar_periodo_plazo_sala')->value('descripcion'));
        $this->assertSame(3, Bitacora::where('actor_nombre', 'Jefatura')->where('modulo', 'configuracion')->count());
    }
}
