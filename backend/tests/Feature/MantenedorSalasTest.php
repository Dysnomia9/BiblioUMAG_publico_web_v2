<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MantenedorSalasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Un viernes con atención, para que ninguna prueba dependa del día en que corre.
        Carbon::setTestNow('2026-11-13 09:00:00');
        // TestCase lo baja a 2 para otras pruebas: acá importa el mínimo real.
        config(['salas.personas_minimas' => (require config_path('salas.php'))['personas_minimas']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre' => 'Logia 16',
            'codigo' => 'L16',
            'capacidad' => 4,
            'tipo' => 'logia',
            'solo_administracion' => false,
            'solo_responsable' => false,
        ];
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
    }

    public function test_crea_una_sala_y_queda_disponible_en_el_meson_y_en_el_portal(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/mantenedor-salas', $this->datos())->assertStatus(201)
            ->assertJsonPath('codigo', 'L16')
            ->assertJsonPath('activa', true)
            ->assertJsonPath('reservas_count', 0);

        $this->assertContains('L16', collect($this->getJson('/api/salas?fecha=2026-11-13')->assertOk()->json('salas'))->pluck('codigo'));
        $this->assertSame('Logia 16', Sala::porCodigo('16')?->nombre);

        Sanctum::actingAs(Usuario::factory()->create());
        $this->assertContains('L16', collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('codigo'));
    }

    public function test_una_logia_nueva_queda_despues_de_las_otras_logias(): void
    {
        $this->comoAdmin();
        $this->postJson('/api/mantenedor-salas', $this->datos())->assertStatus(201);

        $codigos = collect($this->getJson('/api/mantenedor-salas')->assertOk()->json('salas'))->pluck('codigo')->all();

        $this->assertSame(array_search('L15', $codigos) + 1, array_search('L16', $codigos));
        $this->assertLessThan(array_search('SEM', $codigos), array_search('L16', $codigos));
    }

    public function test_el_codigo_se_guarda_como_lo_busca_el_meson(): void
    {
        $this->comoAdmin();

        foreach ([['l16', 'L16'], [' Logia 17 ', 'L17'], ['18', 'L18'], ['sem2', 'SEM2']] as $i => [$escrito, $guardado]) {
            $this->postJson('/api/mantenedor-salas', $this->datos(['nombre' => "Sala {$i}", 'codigo' => $escrito]))
                ->assertStatus(201)->assertJsonPath('codigo', $guardado);
            $this->assertNotNull(Sala::porCodigo($escrito));
        }
    }

    public function test_valida_los_datos_de_la_sala(): void
    {
        $this->comoAdmin();
        $total = Sala::count();

        $casos = [
            ['nombre', ['nombre' => '']],
            ['nombre', ['nombre' => 'Logia 05']],
            ['codigo', ['codigo' => '']],
            ['codigo', ['codigo' => 'L05']],
            ['codigo', ['codigo' => '5']],
            ['codigo', ['codigo' => '123']],
            ['codigo', ['codigo' => 'SALA-1']],
            ['codigo', ['codigo' => 'CODIGODEMASIADOLARGO']],
            ['capacidad', ['capacidad' => 0]],
            ['capacidad', ['capacidad' => 501]],
            ['capacidad', ['capacidad' => 2]],
            ['tipo', ['tipo' => 'otro']],
        ];
        foreach ($casos as [$campo, $cambios]) {
            $respuesta = $this->postJson('/api/mantenedor-salas', $this->datos($cambios));
            $this->assertSame(422, $respuesta->status(), 'Debió rechazar: '.json_encode($cambios));
            $respuesta->assertJsonValidationErrors($campo);
        }

        $this->assertSame($total, Sala::count());
    }

    public function test_una_sala_a_nombre_de_una_persona_puede_tener_menos_capacidad_y_se_reserva_con_un_rut(): void
    {
        $this->comoAdmin();
        $id = $this->postJson('/api/mantenedor-salas', $this->datos([
            'nombre' => 'Seminario 2', 'codigo' => 'SEM2', 'tipo' => 'sala', 'capacidad' => 1, 'solo_responsable' => true,
        ]))->assertStatus(201)->json('id');
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $this->postJson('/api/reservas', [
            'sala_id' => $id, 'fecha' => '2026-11-13', 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'cantidad_personas' => 1, 'ruts' => [$docente->rut],
        ])->assertStatus(201);
    }

    public function test_editar_una_sala_conserva_sus_reservas_y_queda_en_la_bitacora(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['nombre' => 'Logia 500', 'codigo' => 'L500', 'capacidad' => 3]);
        $reserva = Reserva::factory()->create(['sala_id' => $sala->id]);

        $this->putJson("/api/mantenedor-salas/{$sala->id}", $this->datos(['nombre' => 'Logia 501', 'codigo' => 'L501', 'capacidad' => 6]))
            ->assertOk()
            ->assertJsonPath('nombre', 'Logia 501')
            ->assertJsonPath('reservas_count', 1);

        $this->assertSame($sala->id, $reserva->fresh()->sala_id);
        $registro = Bitacora::where('accion', 'salas.editar')->first();
        $this->assertSame('Editó la sala Logia 501 (L501)', $registro->descripcion);
        $this->assertNotEmpty($registro->datos);
    }

    public function test_editar_una_sala_sin_cambiarle_el_codigo_no_choca_consigo_misma(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['nombre' => 'Logia 500', 'codigo' => 'L500']);

        $this->putJson("/api/mantenedor-salas/{$sala->id}", $this->datos(['nombre' => 'Logia 500', 'codigo' => 'L500', 'capacidad' => 5]))
            ->assertOk()->assertJsonPath('capacidad', 5);
    }

    public function test_una_sala_de_baja_no_se_ofrece_ni_se_puede_reservar_y_conserva_su_historial(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['codigo' => 'L500', 'nombre' => 'Logia 500']);
        $pasada = Reserva::factory()->create(['sala_id' => $sala->id, 'fecha' => '2026-10-01', 'estado' => 'finalizada']);
        $integrantes = Usuario::factory()->count(3)->create();

        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => false])->assertOk()->assertJsonPath('activa', false);

        $this->assertNotContains('L500', collect($this->getJson('/api/salas?fecha=2026-11-13')->json('salas'))->pluck('codigo'));
        $reservar = ['sala_id' => $sala->id, 'fecha' => '2026-11-13', 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'cantidad_personas' => 3, 'ruts' => $integrantes->pluck('rut')->all()];
        $this->postJson('/api/reservas', $reservar)->assertStatus(422)->assertJsonValidationErrors('sala_id');

        // Sigue en el historial, y el filtro del listado la ofrece marcada como de baja.
        $listado = $this->getJson('/api/reservas?vista=todas&sala_id='.$sala->id)->assertOk();
        $this->assertSame([$pasada->id], collect($listado->json('data'))->pluck('id')->all());
        $this->assertFalse(collect($listado->json('salas'))->firstWhere('id', $sala->id)['activa']);

        Sanctum::actingAs($integrantes[0]);
        $this->assertNotContains('L500', collect($this->getJson('/api/mi/salas')->json('salas'))->pluck('codigo'));
        $this->postJson('/api/mi/reservas', $reservar)->assertStatus(422)->assertJsonValidationErrors('sala_id');
    }

    public function test_no_se_puede_mover_una_reserva_a_una_sala_de_baja(): void
    {
        $this->comoAdmin();
        $reserva = Reserva::factory()->create(['fecha' => '2026-11-13', 'hora_inicio' => '10:00', 'hora_fin' => '11:00']);
        $deBaja = Sala::factory()->create(['activa' => false]);

        $this->putJson("/api/reservas/{$reserva->id}", ['sala_id' => $deBaja->id, 'fecha' => '2026-11-13', 'hora_inicio' => '10:00', 'hora_fin' => '11:00'])
            ->assertStatus(422)->assertJsonValidationErrors('sala_id');
    }

    public function test_reactivar_la_vuelve_a_ofrecer(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['codigo' => 'L500', 'activa' => false]);

        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => true])->assertOk()->assertJsonPath('activa', true);

        $this->assertContains('L500', collect($this->getJson('/api/salas?fecha=2026-11-13')->json('salas'))->pluck('codigo'));
        $this->assertSame(1, Bitacora::where('accion', 'salas.reactivar')->count());
    }

    public function test_no_se_da_de_baja_una_sala_con_reservas_activas(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['nombre' => 'Logia 500']);
        Reserva::factory()->create(['sala_id' => $sala->id, 'fecha' => '2026-11-14', 'hora_inicio' => '10:00', 'hora_fin' => '11:00']);

        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => false])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Logia 500 tiene 1 reserva activa: cancélala o muévela a otra sala antes de darla de baja.');

        $this->assertTrue($sala->fresh()->activa);
        $this->assertSame(0, Bitacora::where('accion', 'salas.dar_de_baja')->count());
    }

    public function test_una_reserva_a_la_que_nadie_llego_no_impide_darla_de_baja(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create();
        $vencida = Reserva::factory()->create(['sala_id' => $sala->id, 'fecha' => '2026-11-13', 'hora_inicio' => '08:00', 'hora_fin' => '08:30']);
        $vencida->created_at = '2026-11-12 10:00:00';
        $vencida->save();

        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => false])->assertOk();

        $this->assertSame('no_show', $vencida->fresh()->estado);
    }

    public function test_una_llave_sin_devolver_si_impide_darla_de_baja(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create();
        Reserva::factory()->create(['sala_id' => $sala->id, 'fecha' => '2026-11-12', 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'hora_prestamo_real' => '2026-11-12 10:01:00']);

        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => false])->assertStatus(409);
    }

    public function test_se_elimina_una_sala_sin_reservas_y_queda_en_la_bitacora(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['nombre' => 'Logia 500', 'codigo' => 'L500']);

        $this->deleteJson("/api/mantenedor-salas/{$sala->id}")->assertStatus(204);

        $this->assertNull(Sala::find($sala->id));
        $this->assertSame('Eliminó la sala Logia 500 (L500)', Bitacora::where('accion', 'salas.eliminar')->value('descripcion'));
    }

    public function test_una_sala_con_historial_no_se_elimina_y_no_se_pierde_ninguna_reserva(): void
    {
        $this->comoAdmin();
        $sala = Sala::factory()->create(['nombre' => 'Logia 500', 'activa' => false]);
        $reserva = Reserva::factory()->create(['sala_id' => $sala->id, 'fecha' => '2026-10-01', 'estado' => 'finalizada']);

        $this->deleteJson("/api/mantenedor-salas/{$sala->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Logia 500 tiene reservas en su historial: no se puede eliminar, pero sí dar de baja.');

        $this->assertNotNull(Sala::find($sala->id));
        $this->assertNotNull($reserva->fresh());
    }

    public function test_la_base_tampoco_deja_borrar_las_reservas_junto_con_la_sala(): void
    {
        $sala = Sala::factory()->create();
        Reserva::factory()->create(['sala_id' => $sala->id]);

        $this->expectException(QueryException::class);

        $sala->delete();
    }

    public function test_solo_quien_gestiona_los_catalogos_administra_las_salas(): void
    {
        $sala = Sala::factory()->create();
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/mantenedor-salas')->assertStatus(403);
        $this->postJson('/api/mantenedor-salas', $this->datos())->assertStatus(403);
        $this->putJson("/api/mantenedor-salas/{$sala->id}", $this->datos())->assertStatus(403);
        $this->patchJson("/api/mantenedor-salas/{$sala->id}/activa", ['activa' => false])->assertStatus(403);
        $this->deleteJson("/api/mantenedor-salas/{$sala->id}")->assertStatus(403);

        $this->assertTrue($sala->fresh()->activa);
        $this->assertNull(Sala::where('codigo', 'L16')->first());
    }
}
