<?php

namespace Tests\Feature;

use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La Sala de Seminarios no se presta a estudiantes: no está en el portal y en el mesón la reserva solo la administración. */
class SalaSoloAdministracionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        $this->assertSame(1, Sala::where('solo_administracion', true)->count());
    }

    public function test_no_aparece_en_el_portal_ni_se_puede_reservar_desde_ahi(): void
    {
        Carbon::setTestNow('2026-07-10 09:00:00'); // viernes
        [$yo, $companero] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($yo);

        $codigos = collect($this->getJson('/api/mi/salas')->assertOk()->json('salas'))->pluck('codigo');
        $this->assertNotContains('SEM', $codigos);
        $this->assertContains('L01', $codigos);

        $this->postJson('/api/mi/reservas', $this->reserva($this->seminarios(), [$yo->rut, $companero->rut]))
            ->assertForbidden();
    }

    public function test_en_el_meson_sin_el_permiso_no_se_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $otro = Usuario::factory()->create(['tipo' => 'funcionario']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut, $otro->rut]))
            ->assertForbidden();
    }

    public function test_la_administracion_la_reserva_para_un_docente_pero_no_para_un_estudiante(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $docente = Usuario::factory()->create(['tipo' => 'docente']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante']);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$estudiante->rut, $docente->rut]))
            ->assertStatus(422);

        $this->postJson('/api/reservas', $this->reserva($this->seminarios(), [$docente->rut, $estudiante->rut]))
            ->assertCreated();
    }

    public function test_las_demas_salas_se_siguen_reservando_igual(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$uno, $dos] = Usuario::factory()->count(2)->create();

        $this->postJson('/api/reservas', $this->reserva(Sala::where('codigo', 'L01')->sole(), [$uno->rut, $dos->rut]))
            ->assertCreated();
    }
}
