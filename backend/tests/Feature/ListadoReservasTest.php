<?php

namespace Tests\Feature;

use App\Models\Bitacora;
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
 * Gestión › Listado de reservas: todas las reservas de salas por pestaña, con
 * búsqueda y filtros, y la edición (sala, día y horario) para quien tiene el permiso
 * de editar reservas.
 */
class ListadoReservasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 14:05:00'); // viernes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<Usuario>  $integrantes */
    private function reserva(Sala $sala, array $integrantes, array $atributos = []): Reserva
    {
        return Reserva::factory()->conParticipantes($integrantes)->create([
            'sala_id' => $sala->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '16:00',
            'hora_fin' => '18:00',
            'usuario_id' => $integrantes[0]->id,
            'rut_usuario' => $integrantes[0]->rut,
            'cantidad_personas' => count($integrantes),
            ...$atributos,
        ]);
    }

    /** Puede editar reservas, pero no agendar en horas ya pasadas. */
    private function editorDeReservas(): Staff
    {
        Rol::create(['clave' => 'editor_reservas', 'nombre' => 'Editor de reservas', 'nivel' => 4, 'permisos' => ['reservas.editar', 'listados.ver']]);
        $staff = Staff::factory()->create(['rol' => 'editor_reservas']);
        Sanctum::actingAs($staff);

        return $staff;
    }

    public function test_separa_las_reservas_por_pestana_y_libera_las_vencidas_sin_confirmar(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $sala = Sala::factory()->create(['nombre' => 'Logia 01']);
        [$ana, $beto] = Usuario::factory()->count(2)->create();

        $activa = $this->reserva($sala, [$ana, $beto]);
        $finalizada = $this->reserva($sala, [$ana, $beto], [
            'fecha' => '2026-08-13', 'estado' => 'finalizada',
            'hora_prestamo_real' => '2026-08-13 16:02:00', 'hora_devolucion_real' => '2026-08-13 17:55:00', 'prestado_por' => 'Mesón',
        ]);
        $cancelada = $this->reserva($sala, [$ana, $beto], ['fecha' => '2026-08-12', 'estado' => 'cancelada']);
        // Ayer nadie llegó y seguía activa: nadie había vuelto a mirar ese día.
        $olvidada = $this->reserva($sala, [$ana, $beto], ['fecha' => '2026-08-13', 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'created_at' => '2026-08-12 12:00:00']);

        $listado = $this->getJson('/api/reservas')
            ->assertOk()
            ->assertJsonPath('conteos', ['activas' => 1, 'finalizadas' => 1, 'no_show' => 1, 'canceladas' => 1, 'todas' => 4])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activa->id)
            ->assertJsonPath('data.0.situacion', 'pendiente')
            ->assertJsonPath('data.0.sala.nombre', 'Logia 01')
            ->assertJsonPath('data.0.hora_inicio', '16:00')
            ->assertJsonPath('data.0.titular.rut', $ana->rut)
            ->assertJsonCount(2, 'data.0.personas');
        // Para el filtro: todas las salas, también la de la prueba.
        $this->assertContains($sala->id, collect($listado->json('salas'))->pluck('id'));

        $this->assertSame('no_show', $olvidada->fresh()->estado);

        // El historial va de lo más reciente hacia atrás.
        $todas = $this->getJson('/api/reservas?vista=todas')->assertOk();
        $this->assertSame([$activa->id, $finalizada->id, $olvidada->id, $cancelada->id], collect($todas->json('data'))->pluck('id')->all());
        $this->getJson('/api/reservas?vista=finalizadas')->assertJsonPath('data.0.situacion', 'finalizada')->assertJsonPath('data.0.prestado_por', 'Mesón');
    }

    public function test_dice_si_el_grupo_esta_en_la_sala_o_si_quedo_la_llave_sin_devolver(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $sala = Sala::factory()->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $enUso = $this->reserva($sala, [$ana, $beto], ['hora_inicio' => '14:00', 'hora_fin' => '15:00', 'hora_prestamo_real' => '2026-08-14 14:02:00']);
        $sinDevolver = $this->reserva($sala, [$ana, $beto], ['fecha' => '2026-08-13', 'hora_prestamo_real' => '2026-08-13 16:01:00']);

        $situaciones = collect($this->getJson('/api/reservas')->assertOk()->json('data'))->pluck('situacion', 'id');

        $this->assertSame('en_uso', $situaciones[$enUso->id]);
        $this->assertSame('sin_devolver', $situaciones[$sinDevolver->id]);
    }

    public function test_busca_por_cualquier_integrante_o_la_sala_y_filtra_por_sala_y_fechas(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $logia = Sala::factory()->create(['nombre' => 'Logia 01']);
        $seminarios = Sala::factory()->create(['nombre' => 'Sala de Seminarios', 'tipo' => 'sala']);
        $ana = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Muñoz', 'rut' => '11.111.111-1']);
        $beto = Usuario::factory()->create(['nombre' => 'Beto', 'apellido' => 'Paredes']);
        $carla = Usuario::factory()->create(['nombre' => 'Carla', 'apellido' => 'Zúñiga']);
        $dani = Usuario::factory()->create(['nombre' => 'Dani', 'apellido' => 'Rojas']);
        $deAna = $this->reserva($logia, [$ana, $beto]);
        $deCarla = $this->reserva($seminarios, [$carla, $dani], ['fecha' => '2026-08-17']);

        $ids = fn (string $consulta) => collect($this->getJson("/api/reservas?vista=todas&{$consulta}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$deAna->id], $ids('q=munoz'));
        $this->assertSame([$deAna->id], $ids('q=111111111'));
        $this->assertSame([$deAna->id], $ids('q=beto+paredes'), 'También encuentra a quien no es el titular');
        $this->assertSame([$deCarla->id], $ids('q=seminarios'));
        $this->assertSame([$deCarla->id], $ids("sala_id={$seminarios->id}"));
        $this->assertSame([$deAna->id], $ids('desde=2026-08-14&hasta=2026-08-15'));
    }

    public function test_consultar_el_listado_no_es_lo_mismo_que_poder_editar(): void
    {
        $sala = Sala::factory()->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = $this->reserva($sala, [$ana, $beto]);
        $cambio = ['sala_id' => $sala->id, 'fecha' => '2026-08-14', 'hora_inicio' => '16:30', 'hora_fin' => '18:00'];

        // Atención de público ve el listado; el bibliotecario reserva salas: ninguno edita.
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->getJson('/api/reservas')->assertOk();
        $this->putJson("/api/reservas/{$reserva->id}", $cambio)->assertForbidden();

        Sanctum::actingAs(Staff::factory()->create());
        $this->putJson("/api/reservas/{$reserva->id}", $cambio)->assertForbidden();

        Rol::create(['clave' => 'solo_entradas', 'nombre' => 'Solo entradas', 'permisos' => ['entradas.registrar']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'solo_entradas']));
        $this->getJson('/api/reservas')->assertForbidden();

        // Con el permiso de editar también se corrigen integrantes y se cancela, sin ser del mesón de salas.
        $this->editorDeReservas();
        $this->putJson("/api/reservas/{$reserva->id}", $cambio)->assertOk();
        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $beto->rut]])->assertOk();
        $this->deleteJson("/api/reservas/{$reserva->id}")->assertNoContent();
        $this->assertSame('cancelada', $reserva->fresh()->estado);
    }

    public function test_editar_cambia_la_sala_el_dia_y_el_horario_y_queda_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $logia1 = Sala::factory()->create(['nombre' => 'Logia 01']);
        $logia2 = Sala::factory()->create(['nombre' => 'Logia 02']);
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = $this->reserva($logia1, [$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $logia2->id, 'fecha' => '2026-08-17', 'hora_inicio' => '10:00', 'hora_fin' => '11:30'])
            ->assertOk()
            ->assertJsonPath('sala.nombre', 'Logia 02')
            ->assertJsonPath('fecha', '2026-08-17')
            ->assertJsonPath('hora_inicio', '10:00')
            ->assertJsonPath('hora_fin', '11:30')
            ->assertJsonPath('situacion', 'pendiente');

        $reserva->refresh();
        $this->assertSame($logia2->id, $reserva->sala_id);
        $this->assertSame('2026-08-17', $reserva->fecha->toDateString());
        $this->assertSame('10:00:00', $reserva->hora_inicio);

        $registro = Bitacora::where('accion', 'salas.editar')->sole();
        $this->assertSame([
            'sala' => ['Logia 01', 'Logia 02'],
            'fecha' => ['14/08/2026', '17/08/2026'],
            'horario' => ['16:00 a 18:00', '10:00 a 11:30'],
        ], $registro->datos);
        $this->assertStringContainsString('Logia 01, 14/08/2026 de 16:00 a 18:00', $registro->descripcion);

        // Guardar sin cambiar nada no deja otra entrada en la bitácora.
        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $logia2->id, 'fecha' => '2026-08-17', 'hora_inicio' => '10:00', 'hora_fin' => '11:30'])->assertOk();
        $this->assertSame(1, Bitacora::where('accion', 'salas.editar')->count());
    }

    public function test_editar_aplica_las_mismas_reglas_que_al_reservar(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $logia1 = Sala::factory()->create();
        $logia2 = Sala::factory()->create();
        [$ana, $beto, $carla, $dani] = Usuario::factory()->count(4)->create();
        $reserva = $this->reserva($logia1, [$ana, $beto]);
        $this->reserva($logia2, [$carla, $dani], ['hora_inicio' => '17:00', 'hora_fin' => '19:00']);
        $this->reserva($logia1, [$beto, $carla], ['fecha' => '2026-08-17', 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
        $editar = fn (array $cambios) => $this->putJson("/api/reservas/{$reserva->id}", [
            'sala_id' => $logia1->id, 'fecha' => '2026-08-14', 'hora_inicio' => '16:00', 'hora_fin' => '18:00', ...$cambios,
        ]);

        $editar(['sala_id' => $logia2->id])->assertStatus(409)->assertJsonPath('message', 'Ese tramo ya se encuentra reservado en esa sala');
        // Beto ya está en la logia 1 el lunes a esa hora (otra reserva): no puede estar en dos.
        $editar(['fecha' => '2026-08-17', 'hora_inicio' => '11:00', 'hora_fin' => '12:00', 'sala_id' => $logia2->id])
            ->assertStatus(409)->assertJsonPath('message', "El RUT {$beto->rut} ya tiene otra sala reservada en ese horario");
        $editar(['fecha' => '2026-08-16'])->assertStatus(422)->assertJsonPath('message', 'La biblioteca no atiende los domingos: elige otro día.');
        $editar(['hora_fin' => '18:30'])->assertStatus(422);
        $editar(['hora_inicio' => '16:15'])->assertStatus(422);

        $this->assertSame('16:00:00', $reserva->fresh()->hora_inicio);

        $finalizada = $this->reserva($logia2, [$ana, $beto], ['fecha' => '2026-08-13', 'estado' => 'finalizada']);
        $this->putJson("/api/reservas/{$finalizada->id}", ['sala_id' => $logia2->id, 'fecha' => '2026-08-17', 'hora_inicio' => '10:00', 'hora_fin' => '11:00'])
            ->assertStatus(409);
    }

    public function test_con_el_grupo_en_la_sala_se_cambia_el_horario_pero_no_el_dia(): void
    {
        $this->editorDeReservas();
        $sala = Sala::factory()->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        // Hecha con «Reservar ahora»: empezó a las 14:03, fuera de la grilla de media hora.
        $reserva = $this->reserva($sala, [$ana, $beto], [
            'hora_inicio' => '14:03', 'hora_fin' => '15:00', 'hora_prestamo_real' => '2026-08-14 14:04:00', 'created_at' => '2026-08-14 14:03:00',
        ]);

        // El inicio ya pasó y no cae en la grilla, pero es el que tenía: se puede alargar.
        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $sala->id, 'fecha' => '2026-08-14', 'hora_inicio' => '14:03', 'hora_fin' => '16:00'])
            ->assertOk()
            ->assertJsonPath('hora_fin', '16:00')
            ->assertJsonPath('situacion', 'en_uso');

        // Otro inicio, en cambio, sí se revisa: ya pasó.
        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $sala->id, 'fecha' => '2026-08-14', 'hora_inicio' => '14:00', 'hora_fin' => '16:00'])
            ->assertStatus(422);

        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $sala->id, 'fecha' => '2026-08-17', 'hora_inicio' => '10:00', 'hora_fin' => '11:00'])
            ->assertStatus(409);
    }

    public function test_sin_el_permiso_de_horas_pasadas_no_se_mueve_a_un_dia_que_ya_paso(): void
    {
        $this->editorDeReservas();
        $sala = Sala::factory()->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = $this->reserva($sala, [$ana, $beto], ['fecha' => '2026-08-17']);

        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $sala->id, 'fecha' => '2026-08-13', 'hora_inicio' => '16:00', 'hora_fin' => '18:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ese día ya pasó: elige hoy o un día próximo.');
    }
}
