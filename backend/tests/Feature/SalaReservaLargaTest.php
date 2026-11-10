<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Rol;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Reservas largas» (salas.sin_limite): desde el panel, quien tiene el permiso reserva una
 * sala por más que el máximo del portal (por ejemplo, la Sala de Postgrado todo el día) y
 * sin la cuota diaria por persona. Lo tienen todos los roles del personal; un rol sin él y
 * el portal siguen con el máximo.
 */
class SalaReservaLargaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservar(Sala $sala, Usuario $usuario, string $desde, string $hasta)
    {
        return $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => $desde,
            'hora_fin' => $hasta,
            'cantidad_personas' => 1,
            'ruts' => [$usuario->rut],
        ]);
    }

    public function test_con_el_permiso_se_reserva_la_sala_todo_el_dia(): void
    {
        // Atención de público también lo tiene (la migración se lo da a todos los roles).
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        Carbon::setTestNow('2026-07-10 08:30:00');

        $this->reservar(Sala::factory()->create(), Usuario::factory()->create(), '08:45', '19:00')
            ->assertCreated()
            ->assertJsonPath('hora_inicio', '08:45:00')
            ->assertJsonPath('hora_fin', '19:00:00');
    }

    public function test_sin_el_permiso_sigue_el_maximo_de_dos_horas(): void
    {
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        Carbon::setTestNow('2026-07-10 08:30:00');

        $this->reservar(Sala::factory()->create(), Usuario::factory()->create(), '08:45', '19:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar entre 30 minutos y 2 horas.');
    }

    public function test_una_reserva_larga_sigue_dentro_del_horario_y_de_la_duracion_minima(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'bibliotecario']));
        Carbon::setTestNow('2026-07-10 08:30:00');
        $sala = Sala::factory()->create();
        $usuario = Usuario::factory()->create();

        $this->reservar($sala, $usuario, '08:45', '21:00')->assertStatus(422);
        $this->reservar($sala, $usuario, '09:00', '09:10')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar al menos 30 minutos.');
    }

    public function test_confirmar_la_llegada_de_una_reserva_larga_no_choca_con_la_cuota(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'bibliotecario']));
        Carbon::setTestNow('2026-07-10 08:30:00');
        $reserva = $this->reservar(Sala::factory()->create(), Usuario::factory()->create(), '08:45', '19:00')->assertCreated()->json();

        // La confirma alguien sin el permiso: la cuota diaria (4 h) no la frena.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        Carbon::setTestNow('2026-07-10 08:50:00');
        $this->patchJson("/api/reservas/{$reserva['id']}/llegada")->assertOk();

        $this->assertNotNull(Reserva::find($reserva['id'])->hora_prestamo_real);
    }

    public function test_el_portal_sigue_con_su_maximo(): void
    {
        // En el portal se reserva en grupo (de 3 a 6): el grupo es válido, la duración no.
        [$yo, $otra, $tercera] = Usuario::factory()->count(3)->create();
        Sanctum::actingAs($yo);
        Carbon::setTestNow('2026-07-10 08:30:00');

        $this->postJson('/api/mi/reservas', [
            'sala_id' => Sala::factory()->create()->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => '09:00',
            'hora_fin' => '19:00',
            'cantidad_personas' => 3,
            'ruts' => [$yo->rut, $otra->rut, $tercera->rut],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La reserva debe durar entre 30 minutos y 2 horas.');
    }

    public function test_el_permiso_lo_tienen_todos_los_roles_del_personal(): void
    {
        foreach (['admin', 'bibliotecario', 'asistente'] as $clave) {
            $this->assertContains('salas.sin_limite', Rol::where('clave', $clave)->first()->permisosEfectivos(), $clave);
        }
    }
}
