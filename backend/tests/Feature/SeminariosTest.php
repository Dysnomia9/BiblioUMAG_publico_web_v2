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

class SeminariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(Sala $sala, Usuario $responsable): array
    {
        return ['sala_id' => $sala->id, 'fecha' => '2026-07-10', 'hora_inicio' => '10:00',
            'hora_fin' => '12:00', 'cantidad_personas' => 1, 'ruts' => [$responsable->rut]];
    }

    public static function personalYResponsables(): iterable
    {
        foreach (['asistente', 'bibliotecario', 'admin'] as $rol) {
            foreach (['SM1', 'SM2'] as $codigo) {
                foreach (['estudiante', 'docente', 'funcionario'] as $tipo) {
                    yield "$rol $codigo $tipo" => [$rol, $codigo, $tipo];
                }
            }
        }
    }

    #[DataProvider('personalYResponsables')]
    public function test_el_personal_autorizado_reserva_entrega_y_recibe(string $rol, string $codigo, string $tipo): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => $rol]));
        $sala = Sala::where('codigo', $codigo)->sole();
        $this->assertSame(['SM1' => 'Seminario 1', 'SM2' => 'Seminario 2'][$codigo], $sala->nombre);
        $responsable = Usuario::factory()->create(['tipo' => $tipo]);
        $id = $this->postJson('/api/reservas', $this->datos($sala, $responsable))->assertCreated()->json('id');

        Carbon::setTestNow('2026-07-10 10:03:00');
        $this->postJson('/api/salas/llave', ['codigo' => strtolower($codigo)])->assertOk()->assertJsonPath('id', $id);
        $this->patchJson("/api/reservas/{$id}/devolver")->assertOk()->assertJsonPath('estado', 'finalizada');
    }

    public function test_no_se_admiten_ruts_sin_usuario_registrado(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();
        $datos = $this->datos(Sala::where('codigo', 'SM1')->sole(), $usuario);
        $this->postJson('/api/reservas', [...$datos, 'ruts' => ['99.999.999-9']])->assertStatus(422);
        $this->assertDatabaseCount('reservas', 0);
    }

    public function test_el_personal_puede_cambiar_el_responsable_a_estudiante_y_trasladar_su_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        foreach (['SM1', 'SM2'] as $codigo) {
            $estudiante = Usuario::factory()->create();
            $docente = Usuario::factory()->create(['tipo' => 'docente']);
            $sala = Sala::where('codigo', $codigo)->sole();
            $reserva = Reserva::factory()->conParticipantes([$docente])->create([
                'sala_id' => $sala->id, 'usuario_id' => $docente->id, 'rut_usuario' => $docente->rut,
                'cantidad_personas' => 1, 'fecha' => '2026-07-10', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            ]);
            $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$estudiante->rut]])->assertOk();
            $this->assertSame($estudiante->rut, $reserva->fresh()->rut_usuario);
            $otroEstudiante = Usuario::factory()->create();
            $otra = Reserva::factory()->conParticipantes([$otroEstudiante])->create([
                'sala_id' => Sala::where('codigo', 'L01')->value('id'), 'fecha' => '2026-07-10',
                'usuario_id' => $otroEstudiante->id, 'rut_usuario' => $otroEstudiante->rut,
                'cantidad_personas' => 1, 'hora_inicio' => '13:00', 'hora_fin' => '14:00',
            ]);
            $this->putJson("/api/reservas/{$otra->id}", [...$this->datos($sala, $otroEstudiante),
                'hora_inicio' => '13:00', 'hora_fin' => '14:00',
            ])->assertOk();
            $this->assertSame($sala->id, $otra->fresh()->sala_id);
        }
    }

    public function test_las_series_admiten_estudiantes_a_cargo(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        foreach (['SM1', 'SM2'] as $codigo) {
            $estudiante = Usuario::factory()->create();
            $datos = [...$this->datos(Sala::where('codigo', $codigo)->sole(), $estudiante),
                'desde' => '2026-07-10', 'hasta' => '2026-07-24', 'dias' => [5],
            ];
            $this->postJson('/api/reservas/repetir', [...$datos, 'confirmar' => false])->assertOk();
            $this->postJson('/api/reservas/repetir', [...$datos, 'confirmar' => true])
                ->assertCreated()->assertJsonPath('reservadas', 3);
        }
        $this->assertDatabaseCount('reservas', 6);
    }

    public function test_los_seminarios_siguen_bloqueados_en_el_portal_aunque_se_cambie_la_marca_de_administracion(): void
    {
        [$estudiante, $otro] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($estudiante);
        foreach (['SM1', 'SM2'] as $codigo) {
            $sala = Sala::where('codigo', $codigo)->sole();
            $sala->update(['solo_administracion' => false]);
            $codigos = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('codigo')->all();
            $this->assertNotContains($codigo, $codigos);
            $this->postJson('/api/mi/reservas', [...$this->datos($sala, $estudiante),
                'cantidad_personas' => 2, 'ruts' => [$estudiante->rut, $otro->rut],
            ])->assertForbidden();
        }
    }

    public function test_se_confirma_la_llegada_de_estudiantes_por_el_personal_autorizado(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        Carbon::setTestNow('2026-07-10 10:03:00');
        foreach (['SM1', 'SM2'] as $codigo) {
            $estudiante = Usuario::factory()->create();
            $reserva = Reserva::factory()->conParticipantes([$estudiante])->create([
                'sala_id' => Sala::where('codigo', $codigo)->value('id'), 'usuario_id' => $estudiante->id,
                'rut_usuario' => $estudiante->rut, 'fecha' => '2026-07-10',
            ]);
            $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();
            $this->assertNotNull($reserva->fresh()->hora_prestamo_real);
        }
    }

    public function test_sin_permiso_no_se_gestionan_ni_se_entregan_las_llaves(): void
    {
        $rol = Rol::create(['clave' => 'sin_seminarios', 'nombre' => 'Sin seminarios',
            'permisos' => ['salas.gestionar', 'reservas.editar', 'salas.administracion']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => $rol->clave]));
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        foreach (['SM1', 'SM2'] as $codigo) {
            $sala = Sala::where('codigo', $codigo)->sole();
            $reserva = Reserva::factory()->conParticipantes([$docente])->create(['sala_id' => $sala->id, 'fecha' => '2026-07-10']);
            $this->postJson('/api/reservas', $this->datos($sala, $docente))->assertForbidden();
            $this->putJson("/api/reservas/{$reserva->id}", $this->datos($sala, $docente))->assertForbidden();
            $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$docente->rut]])->assertForbidden();
            $this->deleteJson("/api/reservas/{$reserva->id}")->assertForbidden();
            foreach (['llegada', 'liberar', 'devolver', 'liberar-resto'] as $accion) {
                $this->patchJson("/api/reservas/{$reserva->id}/{$accion}")->assertForbidden();
            }
            $this->postJson('/api/salas/llave', ['codigo' => $codigo])->assertForbidden();
            $this->assertSame('activa', $reserva->fresh()->estado);
            $this->assertNull($reserva->fresh()->hora_prestamo_real);
        }
    }

    public function test_renombrar_conserva_ids_historial_y_permisos_existentes(): void
    {
        $sm1 = Sala::where('codigo', 'SM1')->sole();
        $sm2 = Sala::where('codigo', 'SM2')->sole();
        $reserva = Reserva::factory()->create(['sala_id' => $sm1->id, 'estado' => 'finalizada']);
        $sm1->update(['codigo' => 'SEM', 'nombre' => 'Seminario 1']);
        $sm2->update(['codigo' => 'SEM2', 'nombre' => 'Seminario 2']);
        $permisos = Rol::where('clave', 'asistente')->sole()->permisos;
        $cantidad = Sala::count();
        $migracion = require database_path('migrations/2026_11_17_000001_seminarios_sm1_sm2.php');
        $migracion->up();
        $migracion->up();
        $this->assertSame('SM1', $sm1->fresh()->codigo);
        $this->assertSame('SM2', $sm2->fresh()->codigo);
        $etiquetas = require database_path('migrations/2026_11_17_000002_etiquetas_seminarios.php');
        $etiquetas->up();
        $etiquetas->up();
        $this->assertSame('Seminario 1', $sm1->fresh()->nombre);
        $this->assertSame('Seminario 2', $sm2->fresh()->nombre);
        $this->assertSame($sm1->id, $reserva->fresh()->sala_id);
        $this->assertSame($cantidad, Sala::count());
        $this->assertSame($permisos, Rol::where('clave', 'asistente')->sole()->permisos);
    }
}
