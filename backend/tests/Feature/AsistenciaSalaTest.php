<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Al confirmar la llegada del grupo a una sala reservada, cada integrante queda
 * presente en la biblioteca: se le registra la entrada si no la tenía hoy, y no se
 * le duplica si ya la había marcado.
 */
class AsistenciaSalaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmar_la_llegada_marca_presente_solo_a_quien_no_habia_entrado_hoy(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);
        Carbon::setTestNow('2026-08-14 14:05:00');

        $ana = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez']);
        $beto = Usuario::factory()->create(['nombre' => 'Beto', 'apellido' => 'Soto']);
        Entrada::factory()->create(['usuario_id' => $ana->id, 'fecha_hora_entrada' => '2026-08-14 10:00:00', 'via' => 'qr']);
        // La de ayer no cuenta: hoy Beto todavía no ha entrado.
        Entrada::factory()->create(['usuario_id' => $beto->id, 'fecha_hora_entrada' => '2026-08-13 10:00:00']);

        $reserva = Reserva::factory()->conParticipantes([$ana, $beto])->create([
            'fecha' => '2026-08-14', 'hora_inicio' => '14:00', 'hora_fin' => '16:00', 'usuario_id' => $ana->id,
        ]);

        $this->patchJson("/api/reservas/{$reserva->id}/llegada")
            ->assertOk()
            ->assertJsonPath('asistencia.registradas', ['Beto Soto'])
            ->assertJsonPath('asistencia.ya_presentes', ['Ana Pérez']);

        $this->assertSame(1, Entrada::where('usuario_id', $ana->id)->whereDate('fecha_hora_entrada', '2026-08-14')->count());
        $this->assertDatabaseHas('entradas', [
            'usuario_id' => $beto->id,
            'via' => 'sala',
            'registrado_por_staff_id' => $staff->id,
            'fecha_hora_entrada' => '2026-08-14 14:05:00',
        ]);
    }

    public function test_despues_de_entrar_por_la_sala_no_se_puede_marcar_otra_entrada_ese_dia(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-14 14:05:00');
        $ana = Usuario::factory()->create();
        $reserva = Reserva::factory()->conParticipantes([$ana])->create(['fecha' => '2026-08-14', 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();
        $this->postJson('/api/entrada', ['rut' => $ana->rut])->assertStatus(409);

        $this->assertSame(1, Entrada::where('usuario_id', $ana->id)->count());
    }

    public function test_el_primer_escaneo_de_la_logia_tambien_marca_presentes_y_el_segundo_no(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-08-14 14:05:00');
        $sala = Sala::factory()->create(['codigo_barras' => 'LOGIA-77']);
        $ana = Usuario::factory()->create();
        $beto = Usuario::factory()->create();
        Reserva::factory()->conParticipantes([$ana, $beto])->create([
            'sala_id' => $sala->id, 'fecha' => '2026-08-14', 'hora_inicio' => '14:00', 'hora_fin' => '16:00',
        ]);

        $this->postJson('/api/salas/scan-logia', ['codigo_barras' => 'LOGIA-77'])
            ->assertOk()
            ->assertJsonCount(2, 'asistencia.registradas');
        $this->postJson('/api/salas/scan-logia', ['codigo_barras' => 'LOGIA-77'])
            ->assertOk()
            ->assertJsonMissingPath('asistencia');

        $this->assertDatabaseCount('entradas', 2);
    }
}
