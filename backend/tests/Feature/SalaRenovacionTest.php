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

class SalaRenovacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10 11:55:00');
        Sanctum::actingAs(Staff::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enUso(array $atributos = []): Reserva
    {
        $usuario = Usuario::factory()->create();

        return Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => Sala::where('codigo', 'L04')->value('id'),
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            'cantidad_personas' => 1,
            'fecha' => '2026-07-10',
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'hora_prestamo_real' => '2026-07-10 10:02:00',
            'prestado_por' => 'Entrega original',
            ...$atributos,
        ]);
    }

    private function renovar(Reserva $reserva, string $desde = '12:00', string $hasta = '14:00')
    {
        return $this->patchJson("/api/reservas/{$reserva->id}/renovar", [
            'hora_fin_anterior' => $desde, 'hora_fin' => $hasta,
        ]);
    }

    public function test_renovaciones_conservan_el_prestamo_y_basta_una_devolucion_manual(): void
    {
        $reserva = $this->enUso();
        $llegada = $reserva->hora_prestamo_real;
        $this->renovar($reserva)->assertOk()->assertJsonPath('id', $reserva->id);
        Carbon::setTestNow('2026-07-10 13:55:00');
        $this->renovar($reserva, '14:00', '16:00')->assertOk();

        $reserva->refresh();
        $this->assertDatabaseCount('reservas', 1);
        $this->assertTrue($reserva->hora_prestamo_real->equalTo($llegada));
        $this->assertSame('Entrega original', $reserva->prestado_por);
        $this->assertSame('10:00:00', $reserva->hora_inicio);
        $this->assertSame('16:00:00', $reserva->hora_fin);
        $this->assertNull($reserva->hora_devolucion_real);
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.renovar', 'entidad_id' => $reserva->id]);

        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk()->assertJsonPath('estado', 'finalizada');
        $this->assertSame(0, Reserva::where('estado', 'activa')->count());
    }

    public function test_una_sola_lectura_del_codigo_devuelve_la_llave_tras_renovar(): void
    {
        $reserva = $this->enUso();
        $this->renovar($reserva)->assertOk();
        Carbon::setTestNow('2026-07-10 13:59:00');
        $this->postJson('/api/salas/llave', ['codigo' => 'L04'])->assertOk()
            ->assertJsonPath('id', $reserva->id)->assertJsonPath('estado', 'finalizada');
        $this->assertSame(0, Reserva::where('estado', 'activa')->count());
    }

    public function test_la_cuota_suma_el_tiempo_original_y_las_renovaciones(): void
    {
        config(['salas.cuota_diaria' => 240]);
        $reserva = $this->enUso();
        $this->renovar($reserva)->assertOk();
        $this->renovar($reserva, '14:00', '14:30')->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$reserva->rut_usuario} ya tiene 4 h reservados hoy; el máximo diario es de 4 h.");
        $this->assertSame('14:00:00', $reserva->fresh()->hora_fin);
    }

    public function test_una_extension_larga_tampoco_elude_la_cuota(): void
    {
        config(['salas.cuota_diaria' => 240]);
        $reserva = $this->enUso();
        $this->renovar($reserva, '12:00', '16:00')->assertStatus(409);
        $this->assertSame('12:00:00', $reserva->fresh()->hora_fin);
    }

    public function test_renovacion_rechaza_el_horario_ocupado_sin_tocar_el_prestamo(): void
    {
        $reserva = $this->enUso();
        Reserva::factory()->create(['sala_id' => $reserva->sala_id, 'hora_inicio' => '12:30:00', 'hora_fin' => '14:00:00']);
        $this->renovar($reserva)->assertStatus(409);
        $this->assertSame('12:00:00', $reserva->fresh()->hora_fin);
        $this->assertNull($reserva->hora_devolucion_real);
    }

    public function test_renovacion_rechaza_un_integrante_con_otra_sala(): void
    {
        $reserva = $this->enUso();
        Reserva::factory()->conParticipantes($reserva->participantes)->create([
            'hora_inicio' => '12:30:00', 'hora_fin' => '14:00:00',
        ]);
        $this->renovar($reserva)->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$reserva->rut_usuario} ya tiene otra sala reservada en ese horario");
    }

    public function test_no_se_renueva_una_reserva_pendiente_o_ya_devuelta(): void
    {
        $reserva = $this->enUso(['hora_prestamo_real' => null]);
        $this->renovar($reserva)->assertStatus(409);
        $reserva->update(['hora_prestamo_real' => '2026-07-10 10:02:00', 'hora_devolucion_real' => now(), 'estado' => 'finalizada']);
        $this->renovar($reserva)->assertStatus(409);
    }

    public function test_renovar_dos_veces_con_el_mismo_horario_anterior_no_duplica_tiempo(): void
    {
        $reserva = $this->enUso();
        $this->renovar($reserva)->assertOk();
        $this->renovar($reserva)->assertStatus(409);
        $this->assertSame('14:00:00', $reserva->fresh()->hora_fin);
        $this->assertDatabaseCount('reservas', 1);
    }

    public function test_se_puede_continuar_si_el_bloque_termino_y_conservan_la_llave(): void
    {
        $reserva = $this->enUso();
        Carbon::setTestNow('2026-07-10 12:05:00');
        $this->renovar($reserva)->assertOk();
        $this->assertSame('10:00:00', $reserva->fresh()->hora_inicio);
    }

    public function test_renovacion_respeta_cierre_duracion_y_permiso_de_reservas_largas(): void
    {
        $reserva = $this->enUso();
        $this->renovar($reserva, '12:00', '21:30')->assertStatus(422);
        $this->renovar($reserva, '12:00', '12:10')->assertStatus(422);
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        $this->renovar($reserva, '12:00', '14:30')->assertStatus(422);
        $this->renovar($reserva)->assertOk();
    }
}
