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

/** Las salas de administración no se solicitan desde el portal; SM1 y SM2 admiten cualquier usuario a cargo desde el mesón autorizado. */
class SalaSoloAdministracionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Con «Salas de la administración» pero sin «Reservas especiales» (salas.repetir): las
     * reglas de siempre. Los administradores tienen las especiales (SalaReservaQueSeRepiteTest).
     */
    private function administracionSinEspeciales(): Staff
    {
        Rol::firstOrCreate(['clave' => 'adm_salas'], ['nombre' => 'Salas de la administración', 'nivel' => 5,
            'permisos' => ['salas.gestionar', 'salas.administracion', 'salas.seminarios', 'reservas.editar', 'listados.ver']]);

        return Staff::factory()->create(['rol' => 'adm_salas']);
    }

    private function seminarios(): Sala
    {
        return Sala::where('codigo', 'SM1')->sole();
    }

    private function reserva(Sala $sala, array $ruts): array
    {
        return [
            'sala_id' => $sala->id,
            'fecha' => '2026-07-10',
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_personas' => count($ruts),
            'ruts' => $ruts,
        ];
    }

    public function test_la_sala_de_seminarios_viene_marcada(): void
    {
        $this->assertTrue($this->seminarios()->solo_administracion);
        $this->assertEqualsCanonicalizing(['SM1', 'SM2', 'COO', 'TPG', 'L09', 'ATEC'], Sala::where('solo_administracion', true)->pluck('codigo')->all());
    }

    public function test_no_aparece_en_el_portal_ni_se_puede_reservar_desde_ahi(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        [$yo, $companero] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $codigos = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('codigo');
        foreach (['SM1', 'SM2', 'COO', 'TPG', 'L09', 'ATEC'] as $deLaAdministracion) {
            $this->assertNotContains($deLaAdministracion, $codigos);
        }
        $this->assertContains('L01', $codigos);

        $this->postJson('/api/mi/reservas', $this->reserva($this->seminarios(), [$yo->rut, $companero->rut]))
            ->assertForbidden();
    }

    public function test_en_el_meson_sin_el_permiso_no_se_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->sinReservasLargas()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut]))
            ->assertForbidden();
    }

    public function test_la_administracion_la_reserva_para_un_docente_o_un_estudiante(): void
    {
        Sanctum::actingAs($this->administracionSinEspeciales());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$estudiante->rut]))
            ->assertCreated();

        $this->postJson('/api/reservas', [...$this->reserva($this->seminarios(), [$docente->rut]), 'hora_inicio' => '13:00', 'hora_fin' => '14:00'])
            ->assertCreated();
    }

    public function test_el_seminario_se_reserva_solo_a_nombre_de_la_persona_a_cargo(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        Sanctum::actingAs($this->administracionSinEspeciales());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante']);
        $this->assertTrue($this->seminarios()->solo_responsable);
        $this->assertEqualsCanonicalizing(['SM1', 'SM2', 'COO', 'TPG', 'ATEC'], Sala::where('solo_responsable', true)->pluck('codigo')->all());

        // Los asistentes no se anotan: con más de un RUT no se reserva.
        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut, $estudiante->rut]))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'Seminario 1 se reserva solo a nombre de quien la pide: ingresa un único RUT, el de la persona a cargo.');

        $id = $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut]))
            ->assertCreated()
            ->assertJsonPath('cantidad_personas', 1)
            ->json('id');
        $this->assertDatabaseCount('reserva_participantes', 1);

        // El mesón sabe cuáles salas van así.
        $sem = collect($this->getJson('/api/salas?fecha=2026-07-10')->assertOk()->json('salas'))->firstWhere('codigo', 'SM1');
        $this->assertTrue($sem['solo_responsable']);

        // Se puede cambiar a otro usuario registrado, incluido un estudiante; dos responsables no.
        $otroDocente = Usuario::factory()->create(['tipo' => 'funcionario']);
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$docente->rut, $otroDocente->rut]])->assertStatus(422);
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$estudiante->rut]])->assertOk();
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$otroDocente->rut]])->assertOk();
    }

    public function test_un_grupo_no_pasa_al_seminario_y_el_seminario_si_a_una_logia(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        Sanctum::actingAs($this->administracionSinEspeciales());
        $logia = Sala::where('codigo', 'L01')->sole();
        // Si está configurado para un responsable, no admite el traslado de una reserva de grupo.
        $uno = Usuario::factory()->create(['tipo' => 'funcionario']);
        $dos = Usuario::factory()->create();
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $grupo = $this->postJson('/api/reservas', $this->reserva($logia, [$uno->rut, $dos->rut]))->assertCreated()->json('id');
        $this->putJson("/api/reservas/{$grupo}", ['sala_id' => $this->seminarios()->id, 'fecha' => '2026-07-10', 'hora_inicio' => '10:00', 'hora_fin' => '12:00'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Seminario 1 se reserva solo a nombre de quien la pide: ingresa un único RUT, el de la persona a cargo. Esta reserva tiene 2 personas: corrige los integrantes antes de pasarla a Seminario 1.');

        $seminario = $this->postJson('/api/reservas', [...$this->reserva($this->seminarios(), [$docente->rut]), 'hora_inicio' => '13:00', 'hora_fin' => '14:00'])->assertCreated()->json('id');
        // En el mesón una logia también se reserva para una sola persona.
        $this->putJson("/api/reservas/{$seminario}", ['sala_id' => $logia->id, 'fecha' => '2026-07-10', 'hora_inicio' => '15:00', 'hora_fin' => '16:00'])->assertOk();
        $this->assertSame($logia->id, Reserva::findOrFail($seminario)->sala_id);
    }

    public function test_las_demas_salas_se_siguen_reservando_igual(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$uno, $dos] = Usuario::factory()->count(2)->create();

        $this->postJson('/api/reservas', $this->reserva(Sala::where('codigo', 'L01')->sole(), [$uno->rut, $dos->rut]))
            ->assertCreated();
    }

    public function test_sala_nueve_logia_nueve_y_aula_tecnologia_no_se_solicitan_en_el_portal(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        $logia = Sala::where('codigo', 'L09')->sole();
        $sala = Sala::factory()->create(['nombre' => 'Sala 9', 'solo_administracion' => false]);
        $aula = Sala::where('codigo', 'ATEC')->sole();
        $reserva = Reserva::factory()->create(['sala_id' => $logia->id, 'estado' => 'finalizada']);
        (require database_path('migrations/2026_11_16_000001_salas_de_administracion.php'))->up();
        [$yo, $companero] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $ids = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('id');
        foreach ([$logia, $sala, $aula] as $restringida) {
            $this->assertTrue($restringida->fresh()->solo_administracion);
            $this->assertNotContains($restringida->id, $ids);
            $this->postJson('/api/mi/reservas', $this->reserva($restringida, [$yo->rut, $companero->rut]))->assertForbidden();
        }
        $this->assertSame($logia->id, $reserva->fresh()->sala_id);
    }

    public function test_el_aula_tecnologia_admite_reservas_especiales_de_uno_a_veinte(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $aula = Sala::where('codigo', 'ATEC')->sole();
        $this->assertSame(20, $aula->capacidad);
        $this->assertSame([1, 20], $aula->personasPermitidas(true));
        $grupo = Usuario::factory()->count(20)->create();

        $this->postJson('/api/reservas', $this->reserva($aula, $grupo->pluck('rut')->all()))->assertCreated();
        $otraPersona = Usuario::factory()->create();
        $this->postJson('/api/reservas', [...$this->reserva($aula, [$otraPersona->rut]), 'hora_inicio' => '13:00', 'hora_fin' => '14:00'])->assertCreated();
        $extra = Usuario::factory()->create();
        $this->postJson('/api/reservas', [...$this->reserva($aula, [...$grupo->pluck('rut')->all(), $extra->rut]), 'hora_inicio' => '15:00', 'hora_fin' => '16:00'])->assertStatus(422);
        (require database_path('migrations/2026_11_16_000001_salas_de_administracion.php'))->up();
        $this->assertSame(1, Sala::where('codigo', 'ATEC')->count());
    }
}
