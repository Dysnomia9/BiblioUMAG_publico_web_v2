<?php

namespace Tests\Feature;

use App\Models\DiaSinAtencion;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReglaPrestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Ubicacion;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Feriados, vacaciones y cierres: la devolución no cae en ellos y no se cobran como atraso. */
class DiaSinAtencionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prestar(Ejemplar $ejemplar, string $devolucion)
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => '2026-07-08',
            'fecha_devolucion' => $devolucion,
        ]);
    }

    public function test_la_devolucion_no_puede_caer_en_un_dia_sin_atencion(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        DiaSinAtencion::create(['fecha' => '2026-07-16', 'motivo' => 'Virgen del Carmen']);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestar($ejemplar, '2026-07-16')->assertStatus(422)
            ->assertJsonPath('errors.fecha_devolucion.0', 'La biblioteca no atiende el 16/07/2026 (Virgen del Carmen): elige otro día para la devolución.');

        $this->prestar($ejemplar, '2026-07-17')->assertCreated();
    }

    public function test_el_cierre_de_una_ubicacion_solo_afecta_a_sus_copias(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $hemeroteca = Ubicacion::factory()->create();
        DiaSinAtencion::create(['fecha' => '2026-07-15', 'ubicacion_id' => $hemeroteca->id, 'motivo' => 'Inventario']);

        $this->prestar(Ejemplar::factory()->create(['ubicacion_id' => $hemeroteca->id]), '2026-07-15')->assertStatus(422);
        $this->prestar(Ejemplar::factory()->create(), '2026-07-15')->assertCreated();
    }

    public function test_la_fecha_propuesta_se_salta_feriados_y_dias_cerrados(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00'); // miércoles
        Sanctum::actingAs(Staff::factory()->create());
        ReglaPrestamo::create(['dias_prestamo' => 4]);
        // Miércoles + 4 = domingo (cerrado); el lunes 13 es feriado: queda el martes 14.
        DiaSinAtencion::create(['fecha' => '2026-07-13', 'motivo' => 'Feriado']);
        $ejemplar = Ejemplar::factory()->create();

        $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => Usuario::factory()->create()->id, 'codigo_barras' => $ejemplar->codigo_barras]))
            ->assertOk()
            ->assertJsonPath('fecha_devolucion', '2026-07-14');
    }

    public function test_no_se_cobra_multa_por_los_dias_sin_atencion(): void
    {
        Carbon::setTestNow('2026-07-14 10:00:00'); // martes
        Sanctum::actingAs(Staff::factory()->create());
        // Vencía el miércoles 8. Días de atraso: jueves 9, viernes 10 (feriado), sábado
        // 11, domingo 12 (cerrado), lunes 13, martes 14 → se cobran 4.
        DiaSinAtencion::create(['fecha' => '2026-07-10', 'motivo' => 'Cierre por fumigación']);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => Ejemplar::factory()->create()->id, 'fecha_devolucion' => '2026-07-08']);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk()
            ->assertJsonPath('multas.0.monto', 4 * config('multas.monto_dia'));
        $this->assertDatabaseHas('multas', ['prestamo_id' => $prestamo->id, 'dias_atraso' => 4]);
    }

    public function test_si_solo_hubo_dias_sin_atencion_no_hay_multa(): void
    {
        Carbon::setTestNow('2026-07-13 10:00:00'); // lunes
        Sanctum::actingAs(Staff::factory()->create());
        // Vencía el viernes 10; sábado 11 y lunes 13 cerrados por vacaciones, domingo cerrado.
        DiaSinAtencion::create(['fecha' => '2026-07-11', 'motivo' => 'Vacaciones']);
        DiaSinAtencion::create(['fecha' => '2026-07-13', 'motivo' => 'Vacaciones']);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => Ejemplar::factory()->create()->id, 'fecha_devolucion' => '2026-07-10']);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk()->assertJsonCount(0, 'multas');
    }

    public function test_el_plazo_automatico_de_retiro_de_una_reserva_se_salta_los_feriados(): void
    {
        // Jueves + 3 días de plazo = domingo; el lunes 13 es feriado: queda el martes.
        Carbon::setTestNow('2026-07-09 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        DiaSinAtencion::create(['fecha' => '2026-07-13']);
        $ejemplar = Ejemplar::factory()->create();

        $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_reserva' => '2026-07-09',
        ])->assertCreated();

        $this->assertSame('2026-07-14', ReservaLibro::first()->fecha_retiro->toDateString());
    }

    public function test_administracion_registra_lista_y_quita_dias_sin_atencion(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $sala = Ubicacion::factory()->create(['nombre' => 'Hemeroteca']);

        $id = $this->postJson('/api/dias-sin-atencion', ['fecha' => '2026-09-18', 'motivo' => 'Fiestas Patrias'])->assertCreated()->json('id');
        $this->postJson('/api/dias-sin-atencion', ['fecha' => '2026-09-18', 'motivo' => 'Otra vez'])->assertStatus(422);
        $this->postJson('/api/dias-sin-atencion', ['fecha' => '2026-09-18', 'ubicacion_id' => $sala->id])->assertCreated();

        $this->getJson('/api/dias-sin-atencion?desde=2026-09-01')->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.fecha', '2026-09-18')
            ->assertJsonPath('0.ubicacion', null)
            ->assertJsonPath('1.ubicacion.nombre', 'Hemeroteca');

        $this->patchJson("/api/dias-sin-atencion/{$id}", ['motivo' => 'Fiestas Patrias (18)'])->assertOk()->assertJsonPath('motivo', 'Fiestas Patrias (18)');
        $this->deleteJson("/api/dias-sin-atencion/{$id}")->assertNoContent();
        $this->assertDatabaseCount('dias_sin_atencion', 1);
    }

    public function test_todo_el_personal_los_consulta_pero_solo_catalogos_los_administra(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/dias-sin-atencion')->assertOk();
        $this->postJson('/api/dias-sin-atencion', ['fecha' => '2026-09-18'])->assertForbidden();
    }

    public function test_la_base_no_admite_el_mismo_dia_dos_veces_para_toda_la_biblioteca(): void
    {
        DiaSinAtencion::create(['fecha' => '2026-09-18']);

        $this->expectException(QueryException::class);
        DiaSinAtencion::create(['fecha' => '2026-09-18']);
    }
}
