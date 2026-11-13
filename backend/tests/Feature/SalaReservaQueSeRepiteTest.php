<?php

namespace Tests\Feature;

use App\Models\DiaSinAtencion;
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
 * Reservas que se repiten semana a semana (permiso salas.repetir) y salas de reserva
 * exclusiva (permiso salas.exclusivas): las maneja solo quien tiene ese permiso.
 */
class SalaReservaQueSeRepiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00'); // lunes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conPermisos(array $permisos): Staff
    {
        Rol::firstOrCreate(['clave' => 'prueba'], ['nombre' => 'Prueba', 'nivel' => 5, 'permisos' => $permisos]);

        return Staff::factory()->create(['rol' => 'prueba']);
    }

    private function serie(Sala $sala, Usuario $docente, array $cambios = []): array
    {
        return [
            'sala_id' => $sala->id,
            'desde' => '2026-03-02',
            'hasta' => '2026-03-30',
            'dias' => [1], // lunes: 2, 9, 16, 23 y 30 de marzo
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => 1,
            'ruts' => [$docente->rut],
            ...$cambios,
        ];
    }

    public function test_sin_el_permiso_no_se_repite(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $this->postJson('/api/reservas/repetir', $this->serie(Sala::where('codigo', 'POS')->sole(), $docente))->assertForbidden();
    }

    public function test_muestra_las_fechas_antes_y_salta_las_que_no_se_pueden(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $sala = Sala::where('codigo', 'POS')->sole();
        $otro = Usuario::factory()->create();
        Reserva::create(['sala_id' => $sala->id, 'usuario_id' => $otro->id, 'rut_usuario' => $otro->rut, 'cantidad_personas' => 1,
            'fecha' => '2026-03-16', 'hora_inicio' => '11:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
        DiaSinAtencion::create(['fecha' => '2026-03-23', 'motivo' => 'Aniversario']);

        $vista = $this->postJson('/api/reservas/repetir', $this->serie($sala, $docente))->assertOk()->json('fechas');
        $this->assertSame(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'], array_column($vista, 'fecha'));
        $this->assertSame([null, null, 'La sala ya está reservada en ese horario.', 'La biblioteca no atiende: Aniversario.', null], array_column($vista, 'motivo'));
        $this->assertSame(1, Reserva::count()); // la vista previa no reserva

        $hecha = $this->postJson('/api/reservas/repetir', $this->serie($sala, $docente, ['confirmar' => true]))->assertCreated();
        $this->assertSame(3, $hecha->json('reservadas'));
        $serie = Reserva::whereNotNull('serie')->orderBy('fecha')->get();
        $this->assertSame(['2026-03-02', '2026-03-09', '2026-03-30'], $serie->map(fn ($r) => $r->fecha->toDateString())->all());
        $this->assertCount(1, $serie->pluck('serie')->unique());

        // Con la serie pendiente, el docente igual puede reservar otra sala otro día.
        $this->postJson('/api/reservas', ['sala_id' => Sala::where('codigo', 'L01')->sole()->id, 'fecha' => '2026-03-03', 'hora_inicio' => '10:00',
            'hora_fin' => '11:00', 'cantidad_personas' => 1, 'ruts' => [$docente->rut]])->assertCreated();
    }

    public function test_cancela_juntas_las_que_quedan(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $serie = $this->postJson('/api/reservas/repetir', $this->serie(Sala::where('codigo', 'POS')->sole(), $docente, ['confirmar' => true]))->json('serie');

        Carbon::setTestNow('2026-03-09 13:00:00'); // ya pasaron las del 2 y del 9
        $this->deleteJson("/api/reservas/serie/{$serie}")->assertOk()->assertJson(['canceladas' => 3]);
        $this->assertSame(['2026-03-02' => 'activa', '2026-03-09' => 'activa', '2026-03-16' => 'cancelada', '2026-03-23' => 'cancelada', '2026-03-30' => 'cancelada'],
            Reserva::where('serie', $serie)->orderBy('fecha')->get()->mapWithKeys(fn ($r) => [$r->fecha->toDateString() => $r->estado])->all());
    }

    public function test_la_sala_exclusiva_la_reserva_solo_quien_tiene_ese_permiso(): void
    {
        $sala = Sala::where('codigo', 'SEM')->sole();
        $sala->update(['reserva_exclusiva' => true]);
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $reserva = ['sala_id' => $sala->id, 'fecha' => '2026-03-03', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_personas' => 1, 'ruts' => [$docente->rut]];

        // «Salas de la administración» no alcanza
        Sanctum::actingAs($this->conPermisos(['salas.gestionar', 'salas.administracion']));
        $this->postJson('/api/reservas', $reserva)->assertForbidden();

        Sanctum::actingAs(Staff::factory()->create(['rol' => Rol::create(['clave' => 'cristian', 'nombre' => 'Encargado', 'nivel' => 5, 'permisos' => ['salas.gestionar', 'salas.exclusivas']])->clave]));
        $this->postJson('/api/reservas', $reserva)->assertCreated();

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $otroDocente = Usuario::factory()->create(['tipo' => 'docente']);
        $this->postJson('/api/reservas', [...$reserva, 'fecha' => '2026-03-04', 'ruts' => [$otroDocente->rut]])->assertCreated();
    }

    public function test_en_el_mantenedor_exclusiva_va_solo_con_solo_administracion(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $sala = Sala::where('codigo', 'POS')->sole();
        $datos = ['nombre' => $sala->nombre, 'codigo' => $sala->codigo, 'capacidad' => $sala->capacidad, 'tipo' => $sala->tipo, 'solo_responsable' => false];

        $this->putJson("/api/mantenedor-salas/{$sala->id}", [...$datos, 'solo_administracion' => false, 'reserva_exclusiva' => true])->assertOk();
        $this->assertFalse($sala->fresh()->reserva_exclusiva);

        $this->putJson("/api/mantenedor-salas/{$sala->id}", [...$datos, 'solo_administracion' => true, 'reserva_exclusiva' => true])->assertOk();
        $this->assertTrue($sala->fresh()->reserva_exclusiva);
    }

    public function test_quitar_las_futuras_desde_el_dia_que_se_abrio(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $serie = $this->postJson('/api/reservas/repetir', $this->serie(Sala::where('codigo', 'POS')->sole(), $docente, ['confirmar' => true]))->json('serie');

        // Se abre la del 16: quedan la del 2 y la del 9, y se quitan la del 16 en adelante.
        $this->deleteJson("/api/reservas/serie/{$serie}?desde=2026-03-16")->assertOk()->assertJson(['canceladas' => 3]);
        $this->assertSame(['activa', 'activa', 'cancelada', 'cancelada', 'cancelada'], Reserva::where('serie', $serie)->orderBy('fecha')->pluck('estado')->all());
    }

    public function test_el_panel_muestra_las_series_con_lo_que_les_queda(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $this->postJson('/api/reservas/repetir', $this->serie(Sala::where('codigo', 'POS')->sole(), $docente, ['dias' => [1, 3], 'confirmar' => true]))->assertCreated();

        Carbon::setTestNow('2026-03-20 09:00:00');
        $serie = $this->getJson('/api/reservas/series')->assertOk()->json('0');
        $this->assertSame([1, 3], $serie['dias']);
        $this->assertSame(['2026-03-02', '2026-03-30', '2026-03-23', 9, 3], [$serie['desde'], $serie['hasta'], $serie['proxima'], $serie['total'], $serie['quedan']]);

        Sanctum::actingAs(Staff::factory()->create());
        $this->getJson('/api/reservas/series')->assertForbidden();
    }

    public function test_con_reservas_especiales_la_sala_de_la_administracion_admite_estudiantes_y_grupos_flexibles(): void
    {
        $sala = Sala::where('codigo', 'SEM')->sole(); // capacidad 30
        $estudiantes = Usuario::factory()->count(20)->create(['tipo' => 'estudiante']);
        $reserva = ['sala_id' => $sala->id, 'fecha' => '2026-03-03', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'cantidad_personas' => 20, 'ruts' => $estudiantes->pluck('rut')->all()];

        // Con solo «Salas de la administración»: el mesón llega a 7 y no a estudiantes
        Sanctum::actingAs($this->conPermisos(['salas.gestionar', 'salas.administracion']));
        $this->postJson('/api/reservas', $reserva)->assertStatus(422);

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->postJson('/api/reservas', $reserva)->assertCreated();
        $this->postJson('/api/reservas', [...$reserva, 'fecha' => '2026-03-04', 'cantidad_personas' => 31, 'ruts' => Usuario::factory()->count(31)->create()->pluck('rut')->all()])
            ->assertStatus(422);
    }
}
