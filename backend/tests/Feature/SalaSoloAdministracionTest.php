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

/** Los seminarios, Cooperación y Moraga no se prestan a estudiantes: no están en el portal y en el mesón los reserva solo la administración. */
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
            'permisos' => ['salas.gestionar', 'salas.administracion', 'reservas.editar', 'listados.ver']]);

        return Staff::factory()->create(['rol' => 'adm_salas']);
    }

    private function seminarios(): Sala
    {
        return Sala::where('codigo', 'SEM')->sole();
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
        $this->assertEqualsCanonicalizing(['SEM', 'SEM2', 'COO', 'MOR'], Sala::where('solo_administracion', true)->pluck('codigo')->all());
    }

    public function test_no_aparece_en_el_portal_ni_se_puede_reservar_desde_ahi(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        [$yo, $companero] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $codigos = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('codigo');
        foreach (['SEM', 'SEM2', 'COO', 'MOR'] as $deLaAdministracion) {
            $this->assertNotContains($deLaAdministracion, $codigos);
        }
        $this->assertContains('L01', $codigos);

        $this->postJson('/api/mi/reservas', $this->reserva($this->seminarios(), [$yo->rut, $companero->rut]))
            ->assertForbidden();
    }

    public function test_en_el_meson_sin_el_permiso_no_se_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut]))
            ->assertForbidden();
    }

    public function test_la_administracion_la_reserva_para_un_docente_pero_no_para_un_estudiante(): void
    {
        Sanctum::actingAs($this->administracionSinEspeciales());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$estudiante->rut]))
            ->assertStatus(422);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut]))
            ->assertCreated();
    }

    public function test_el_seminario_se_reserva_solo_a_nombre_del_docente_a_cargo(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        Sanctum::actingAs($this->administracionSinEspeciales());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante']);
        $this->assertTrue($this->seminarios()->solo_responsable);
        $this->assertEqualsCanonicalizing(['SEM', 'SEM2', 'COO', 'MOR'], Sala::where('solo_responsable', true)->pluck('codigo')->all());

        // Los asistentes no se anotan: con más de un RUT no se reserva.
        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut, $estudiante->rut]))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad_personas.0', 'Seminario 1 se reserva solo a nombre de quien la pide: ingresa un único RUT, el del docente o funcionario a cargo.');

        $id = $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut]))
            ->assertCreated()
            ->assertJsonPath('cantidad_personas', 1)
            ->json('id');
        $this->assertDatabaseCount('reserva_participantes', 1);

        // El mesón sabe cuáles salas van así.
        $sem = collect($this->getJson('/api/salas?fecha=2026-07-10')->assertOk()->json('salas'))->firstWhere('codigo', 'SEM');
        $this->assertTrue($sem['solo_responsable']);

        // Corregir quién la pidió: otro docente sí; dos personas, o un estudiante, no.
        $otroDocente = Usuario::factory()->create(['tipo' => 'funcionario']);
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$docente->rut, $otroDocente->rut]])->assertStatus(422);
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$estudiante->rut]])->assertStatus(422);
        $this->putJson("/api/reservas/{$id}/integrantes", ['ruts' => [$otroDocente->rut]])->assertOk();
    }

    public function test_un_grupo_no_pasa_al_seminario_y_el_seminario_si_a_una_logia(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00');
        Sanctum::actingAs($this->administracionSinEspeciales());
        $logia = Sala::where('codigo', 'L01')->sole();
        // Un grupo a cargo de un funcionario: como el seminario no es para estudiantes, solo falta que sea uno.
        $uno = Usuario::factory()->create(['tipo' => 'funcionario']);
        $dos = Usuario::factory()->create();
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $grupo = $this->postJson('/api/reservas', $this->reserva($logia, [$uno->rut, $dos->rut]))->assertCreated()->json('id');
        $this->putJson("/api/reservas/{$grupo}", ['sala_id' => $this->seminarios()->id, 'fecha' => '2026-07-10', 'hora_inicio' => '10:00', 'hora_fin' => '12:00'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Seminario 1 se reserva solo a nombre de quien la pide: ingresa un único RUT, el del docente o funcionario a cargo. Esta reserva tiene 2 personas: corrige los integrantes antes de pasarla a Seminario 1.');

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
}
