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
 * El código de cada sala (L01 a L15, SEM, POS, AGA): con él se entrega y se recibe la llave
 * en el mesón y se sigue cada sala en Reportes.
 */
class CodigoSalaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservaEn(string $codigo, array $atributos = []): Reserva
    {
        return Reserva::factory()->conParticipantes([Usuario::factory()->create()])->create([
            'sala_id' => Sala::where('codigo', $codigo)->value('id'),
            'fecha' => '2026-08-14',
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            ...$atributos,
        ]);
    }

    public function test_cada_sala_tiene_su_codigo(): void
    {
        $codigos = Sala::pluck('codigo', 'nombre');

        $this->assertSame('L04', $codigos['Logia 04']);
        $this->assertSame('L15', $codigos['Logia 15']);
        $this->assertSame('SEM', $codigos['Sala de Seminarios']);
        $this->assertSame('POS', $codigos['Sala de Postgrado']);
        $this->assertSame('AGA', $codigos['Sala AGACI']);
        $this->assertCount(18, $codigos->unique());
    }

    public function test_la_llave_se_entrega_y_se_recibe_con_el_codigo_o_el_numero(): void
    {
        $reserva = $this->reservaEn('L04');

        Carbon::setTestNow('2026-08-14 10:03:00');
        $this->postJson('/api/salas/llave', ['codigo' => 'l4'])->assertOk()
            ->assertJsonPath('id', $reserva->id)
            ->assertJsonPath('via', 'codigo')
            ->assertJsonPath('sala.codigo', 'L04');

        Carbon::setTestNow('2026-08-14 11:50:00');
        $this->postJson('/api/salas/llave', ['codigo' => 'Logia 4'])->assertOk()->assertJsonPath('estado', 'finalizada');

        $this->assertNotNull($reserva->fresh()->hora_devolucion_real);
    }

    public function test_sirve_para_las_salas_con_nombre_propio_y_avisa_un_codigo_que_no_existe(): void
    {
        $reserva = $this->reservaEn('SEM');
        Carbon::setTestNow('2026-08-14 10:05:00');

        $this->postJson('/api/salas/llave', ['codigo' => 'sem'])->assertOk()->assertJsonPath('id', $reserva->id);
        $this->postJson('/api/salas/llave', ['codigo' => '99'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'No hay ninguna sala con el código «99». Las logias van de L01 a L15; también sirve solo el número.');
        $this->postJson('/api/salas/llave', ['codigo' => 'L05'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Logia 05 no tiene una reserva vigente en este momento');
    }

    public function test_reportes_muestran_cada_sala_con_su_codigo_y_su_detalle(): void
    {
        $this->reservaEn('L04', ['estado' => 'finalizada', 'hora_prestamo_real' => '2026-08-14 10:02:00', 'hora_devolucion_real' => '2026-08-14 12:00:00']);
        $this->reservaEn('L04', ['hora_inicio' => '14:00:00', 'hora_fin' => '14:40:00', 'hora_fin_reservada' => '16:00:00', 'estado' => 'finalizada', 'hora_prestamo_real' => '2026-08-14 14:01:00', 'hora_devolucion_real' => '2026-08-14 14:40:00']);
        $this->reservaEn('L04', ['hora_inicio' => '17:00:00', 'hora_fin' => '18:00:00', 'estado' => 'no_show']);
        $this->reservaEn('SEM', ['estado' => 'cancelada']);

        $resumen = $this->getJson('/api/reportes/resumen?tab=logias&periodo=mes&fecha=2026-08-14')->assertOk();

        $resumen->assertJsonPath('porSala.0', ['label' => 'L04 · Logia 04', 'value' => 3]);
        $resumen->assertJsonPath('porSalaDetalle', [[
            'codigo' => 'L04', 'nombre' => 'Logia 04', 'reservas' => 3, 'presentes' => 2, 'no_llegaron' => 1, 'minutos_uso' => 160, 'liberadas' => 1,
        ]]);
    }
}
