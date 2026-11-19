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

class SalaActividadEditarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        Rol::create(['clave' => 'admin_salas', 'nombre' => 'Admin salas', 'permisos' => ['salas.gestionar', 'salas.administracion']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin_salas']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservaPorNombre(array $extra = []): Reserva
    {
        return Reserva::factory()->create([
            'sala_id' => Sala::where('codigo', 'COO')->value('id'), 'usuario_id' => null, 'rut_usuario' => null,
            'nombre_actividad' => 'Luis Medina', 'descripcion_actividad' => 'Clase de Física II', 'cantidad_personas' => 10,
            'fecha' => '2026-11-18', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', ...$extra,
        ]);
    }

    public function test_corrige_la_persona_a_cargo_y_la_descripcion(): void
    {
        $reserva = $this->reservaPorNombre();

        $this->putJson("/api/reservas/{$reserva->id}/actividad", [
            'nombre_actividad' => '  Luisa Medina ', 'descripcion_actividad' => 'Clase de Física III',
        ])->assertOk()->assertJsonPath('nombre_actividad', 'Luisa Medina');

        $reserva->refresh();
        $this->assertSame('Luisa Medina', $reserva->nombre_actividad);
        $this->assertSame('Clase de Física III', $reserva->descripcion_actividad);
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.actividad', 'entidad_id' => $reserva->id]);

        // Vaciar la descripción la deja en null; el nombre es obligatorio.
        $this->putJson("/api/reservas/{$reserva->id}/actividad", ['nombre_actividad' => 'Luisa Medina', 'descripcion_actividad' => ''])->assertOk();
        $this->assertNull($reserva->fresh()->descripcion_actividad);
        $this->putJson("/api/reservas/{$reserva->id}/actividad", ['nombre_actividad' => '   '])->assertUnprocessable();
    }

    public function test_no_se_edita_una_reserva_con_rut_ni_una_finalizada(): void
    {
        $usuario = Usuario::factory()->create(['tipo' => 'funcionario']);
        $conRut = Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => Sala::where('codigo', 'COO')->value('id'), 'usuario_id' => $usuario->id, 'rut_usuario' => $usuario->rut,
            'cantidad_personas' => 1, 'fecha' => '2026-11-18', 'hora_inicio' => '13:00', 'hora_fin' => '14:00',
        ]);
        $this->putJson("/api/reservas/{$conRut->id}/actividad", ['nombre_actividad' => 'Otro'])->assertConflict();
        $this->assertNull($conRut->fresh()->nombre_actividad);

        $finalizada = $this->reservaPorNombre(['estado' => 'finalizada']);
        $this->putJson("/api/reservas/{$finalizada->id}/actividad", ['nombre_actividad' => 'Otro'])->assertConflict();
        $this->assertSame('Luis Medina', $finalizada->fresh()->nombre_actividad);
    }

    public function test_sin_permiso_de_administracion_no_se_edita(): void
    {
        $reserva = $this->reservaPorNombre();
        Rol::create(['clave' => 'meson', 'nombre' => 'Mesón', 'permisos' => ['salas.gestionar']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'meson']));

        $this->putJson("/api/reservas/{$reserva->id}/actividad", ['nombre_actividad' => 'Otro'])->assertForbidden();
        $this->assertSame('Luis Medina', $reserva->fresh()->nombre_actividad);
    }
}
