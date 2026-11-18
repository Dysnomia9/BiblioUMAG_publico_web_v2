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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalaMesonAdministracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:05:00');
        Rol::create(['clave' => 'meson_llaves', 'nombre' => 'Mesón de llaves', 'permisos' => ['salas.gestionar']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'meson_llaves', 'nombre' => 'Personal del mesón']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function salas(): iterable
    {
        foreach (['SM1', 'SM2', 'MOR', 'COO', 'L09', 'ATEC'] as $codigo) {
            yield $codigo => [$codigo, false];
        }
        yield 'exclusiva' => ['COO', true];
    }

    private function reserva(string $codigo, bool $exclusiva): Reserva
    {
        $sala = Sala::where('codigo', $codigo)->sole();
        $sala->update(['reserva_exclusiva' => $exclusiva]);
        // La autorización del responsable se decidió al crear la reserva; el
        // mesón no debe volver a exigir permisos para reservarla al entregar la llave.
        $usuario = Usuario::factory()->create(['tipo' => 'estudiante']);

        return Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => $sala->id, 'usuario_id' => $usuario->id, 'rut_usuario' => $usuario->rut,
            'cantidad_personas' => 1, 'fecha' => '2026-11-18', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
        ]);
    }

    #[DataProvider('salas')]
    public function test_el_meson_confirma_asistencia_y_recibe_la_llave_sin_permisos_para_agendar(string $codigo, bool $exclusiva): void
    {
        $reserva = $this->reserva($codigo, $exclusiva);
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk()->assertJsonCount(1, 'asistencia.registradas');
        $this->assertDatabaseHas('entradas', ['usuario_id' => $reserva->usuario_id, 'via' => 'sala']);
        $this->assertSame('Personal del mesón', $reserva->fresh()->prestado_por);
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertConflict();
        $this->assertDatabaseCount('entradas', 1);
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk()->assertJsonPath('estado', 'finalizada');
        $this->assertSame('Personal del mesón', $reserva->fresh()->devuelto_por);
        $this->assertDatabaseHas('bitacora', ['accion' => 'salas.llegada', 'entidad_id' => $reserva->id, 'actor_nombre' => 'Personal del mesón']);
    }

    #[DataProvider('salas')]
    public function test_el_codigo_entrega_y_recibe_la_llave_pero_no_permite_cambiar_la_reserva(string $codigo, bool $exclusiva): void
    {
        $reserva = $this->reserva($codigo, $exclusiva);
        $this->deleteJson("/api/reservas/{$reserva->id}")->assertForbidden();
        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$reserva->rut_usuario]])->assertForbidden();
        $this->patchJson("/api/reservas/{$reserva->id}/liberar-resto")->assertForbidden();
        $this->postJson('/api/salas/llave', ['codigo' => $codigo])->assertOk()->assertJsonCount(1, 'asistencia.registradas');
        $this->postJson('/api/salas/llave', ['codigo' => $codigo])->assertOk()->assertJsonPath('estado', 'finalizada');
        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_tambien_confirma_una_actividad_institucional_sin_rut(): void
    {
        $reserva = Reserva::factory()->create([
            'sala_id' => Sala::where('codigo', 'COO')->value('id'), 'usuario_id' => null, 'rut_usuario' => null,
            'nombre_actividad' => 'Reunión institucional', 'cantidad_personas' => 10,
            'fecha' => '2026-11-18', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
        ]);
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();
        $this->assertDatabaseCount('entradas', 0);
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertOk()->assertJsonPath('estado', 'finalizada');
    }

    public function test_sin_permiso_de_salas_no_se_pueden_atender_las_llaves(): void
    {
        $reserva = $this->reserva('COO', false);
        Rol::create(['clave' => 'sin_salas', 'nombre' => 'Sin salas', 'permisos' => ['usuarios.ver']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'sin_salas']));
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertForbidden();
        $this->patchJson("/api/reservas/{$reserva->id}/devolver")->assertForbidden();
        $this->postJson('/api/salas/llave', ['codigo' => 'COO'])->assertForbidden();
        $this->assertNull($reserva->fresh()->hora_prestamo_real);
    }
}
