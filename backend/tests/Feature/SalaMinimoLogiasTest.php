<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Mínimo de personas para salas o logias» (Administración › Salas): un interruptor y un
 * número. Encendido, vale como mínimo en todas las logias, en el mesón y en el portal;
 * apagado, vuelven los rangos de config/salas.php.
 */
class SalaMinimoLogiasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['salas' => require config_path('salas.php')]);   // los rangos reales: 3 a 6 y 1 a 7
        Carbon::setTestNow('2026-07-10 09:00:00');                // viernes con atención
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fijar(bool $activo, int $minimo = 3)
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        return $this->putJson('/api/mantenedor-salas/minimo-logias', ['activo' => $activo, 'minimo' => $minimo]);
    }

    private function reserva(string $codigo, array $ruts, string $inicio = '10:00'): array
    {
        return [
            'sala_id' => Sala::where('codigo', $codigo)->value('id'),
            'fecha' => '2026-07-10',
            'hora_inicio' => $inicio,
            'hora_fin' => Carbon::parse($inicio)->addHour()->format('H:i'),
            'cantidad_personas' => count($ruts),
            'ruts' => $ruts,
        ];
    }

    public function test_apagado_rigen_los_rangos_de_siempre(): void
    {
        $logia = Sala::where('codigo', 'L02')->first();
        $this->assertNull(Sala::minimoLogias());
        $this->assertSame([3, 6], $logia->personasEnElPortal());
        $this->assertSame([1, 7], $logia->personasPermitidas());
    }

    public function test_encendido_vale_para_las_logias_y_no_para_las_salas(): void
    {
        $this->fijar(true, 1)->assertOk()->assertJsonPath('activo', true)->assertJsonPath('minimo', 1);

        $this->assertSame([1, 6], Sala::where('codigo', 'L02')->first()->personasEnElPortal());
        $this->assertSame([3, 6], Sala::where('codigo', 'POS')->first()->personasEnElPortal());

        // Desde el portal, una persona sola reserva una logia, pero no la Sala de Postgrado
        $yo = Usuario::factory()->create();
        Sanctum::actingAs($yo);
        $this->getJson('/api/mi/salas?fecha=2026-07-10')->assertOk()->assertJsonPath('personas_minimas_logias', 1);
        $this->postJson('/api/mi/reservas', $this->reserva('POS', [$yo->rut]))
            ->assertStatus(422)->assertJsonPath('errors.cantidad_personas.0', 'La reserva necesita al menos 3 personas.');
        $this->postJson('/api/mi/reservas', $this->reserva('L02', [$yo->rut]))->assertCreated();
    }

    public function test_un_minimo_mas_alto_tambien_rige_en_el_meson(): void
    {
        $this->fijar(true, 5)->assertOk();
        $ruts = Usuario::factory()->count(5)->create()->pluck('rut')->all();

        Sanctum::actingAs(Staff::factory()->create());
        $this->getJson('/api/salas?fecha=2026-07-10')->assertOk()->assertJsonPath('personas_minimas_logias', 5);
        $this->postJson('/api/reservas', $this->reserva('L02', array_slice($ruts, 0, 4)))
            ->assertStatus(422)->assertJsonPath('errors.cantidad_personas.0', 'La reserva necesita al menos 5 personas.');
        $this->postJson('/api/reservas', $this->reserva('L02', $ruts))->assertCreated();
    }

    public function test_en_el_portal_no_pasa_del_maximo_y_avisa_las_logias_chicas(): void
    {
        $respuesta = $this->fijar(true, 7)->assertOk();

        $this->assertSame([6, 6], Sala::where('codigo', 'L02')->first()->personasEnElPortal());
        $this->assertSame([7, 7], Sala::where('codigo', 'L02')->first()->personasPermitidas());
        // Todas las logias sembradas tienen capacidad 2 a 4: se avisan, no se bloquea
        $this->assertContains('L02', $respuesta->json('logias_chicas'));
    }

    public function test_si_alguien_se_sale_cuenta_el_minimo_de_la_logia(): void
    {
        $this->fijar(true, 2)->assertOk();
        $grupo = Usuario::factory()->count(3)->create();
        $reserva = Reserva::factory()->conParticipantes($grupo)->create([
            'sala_id' => Sala::where('codigo', 'L02')->value('id'),
            'fecha' => '2026-07-10', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'usuario_id' => $grupo[0]->id, 'rut_usuario' => $grupo[0]->rut, 'cantidad_personas' => 3,
        ]);

        // Con mínimo 2, al salir uno quedan 2: la reserva sigue (con el de siempre, 3, se cancelaría)
        Sanctum::actingAs($grupo[2]);
        $this->patchJson("/api/mi/reservas/{$reserva->id}/salir")->assertOk()->assertJsonPath('cancelada', false);
        $this->assertSame('activa', $reserva->refresh()->estado);
    }

    public function test_apagarlo_vuelve_a_lo_de_siempre_y_queda_en_la_bitacora(): void
    {
        $this->fijar(true, 1)->assertOk();
        $this->fijar(false, 1)->assertOk()->assertJsonPath('activo', false)->assertJsonPath('logias_chicas', []);

        $this->assertSame([3, 6], Sala::where('codigo', 'L02')->first()->personasEnElPortal());
        $this->assertSame(2, Bitacora::where('accion', 'configuracion.minimo_logias')->count());
    }

    public function test_el_minimo_va_de_1_a_7_y_solo_lo_cambia_administracion(): void
    {
        $this->fijar(true, 0)->assertStatus(422);
        $this->fijar(true, 8)->assertStatus(422)->assertJsonPath('errors.minimo.0', 'El mínimo va de 1 a 7 personas.');

        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->putJson('/api/mantenedor-salas/minimo-logias', ['activo' => true, 'minimo' => 2])->assertForbidden();
    }
}
