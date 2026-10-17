<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Entrada;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Rut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Al confirmar la llegada (o desde el detalle de la reserva), el personal puede
 * corregir quiénes vienen: quitar a quien no vino, sumar a otra persona o arreglar un
 * RUT mal escrito, con las mismas reglas que al reservar.
 */
class SalaIntegrantesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 14:05:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<Usuario>  $integrantes */
    private function reserva(array $integrantes, array $atributos = []): Reserva
    {
        return Reserva::factory()->conParticipantes($integrantes)->create([
            'fecha' => '2026-08-14',
            'hora_inicio' => '14:00',
            'hora_fin' => '16:00',
            'usuario_id' => $integrantes[0]->id,
            'rut_usuario' => $integrantes[0]->rut,
            'cantidad_personas' => count($integrantes),
            ...$atributos,
        ]);
    }

    public function test_quitar_a_quien_no_vino_y_sumar_a_otra_persona(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $dani = Usuario::factory()->create(['nombre' => 'Dani', 'apellido' => 'Rojas']);
        $reserva = $this->reserva([$ana, $beto, $carla]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $carla->rut, $dani->rut]])
            ->assertOk()
            ->assertJsonPath('cantidad_personas', 3)
            ->assertJsonCount(3, 'personas')
            ->assertJsonPath('asistencia', null);

        $this->assertEqualsCanonicalizing([$ana->id, $carla->id, $dani->id], $reserva->participantes()->pluck('usuarios.id')->all());
        $this->assertSame($ana->id, $reserva->fresh()->usuario_id);

        $registro = Bitacora::where('accion', 'salas.integrantes')->sole();
        $this->assertSame($dani->descripcion(), $registro->datos['agregados']);
        $this->assertSame($beto->descripcion(), $registro->datos['quitados']);
    }

    public function test_se_puede_corregir_un_rut_escrito_sin_puntos_ni_guion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $carla = Usuario::factory()->create(['rut' => '11.111.111-1']);
        $reserva = $this->reserva([$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, '111111111']])->assertOk();

        $this->assertEqualsCanonicalizing([$ana->id, $carla->id], $reserva->participantes()->pluck('usuarios.id')->all());
    }

    public function test_si_se_quita_a_quien_reservo_queda_a_cargo_el_primero_de_la_lista(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $reserva = $this->reserva([$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$carla->rut, $beto->rut]])->assertOk();

        $this->assertSame($carla->id, $reserva->fresh()->usuario_id);
        $this->assertSame($carla->rut, $reserva->fresh()->rut_usuario);
    }

    public function test_sin_cambios_no_queda_registro_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = $this->reserva([$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$beto->rut, $ana->rut]])->assertOk();

        $this->assertSame(0, Bitacora::where('accion', 'salas.integrantes')->count());
    }

    public function test_marca_en_rojo_el_rut_que_no_esta_registrado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = $this->reserva([$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, Rut::formatear(5126663)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ruts.1']);

        $this->assertEqualsCanonicalizing([$ana->id, $beto->id], $reserva->participantes()->pluck('usuarios.id')->all());
    }

    public function test_no_se_puede_dejar_la_reserva_con_menos_de_dos_ni_mas_de_cinco_personas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuarios = Usuario::factory()->count(6)->create();
        $reserva = $this->reserva([$usuarios[0], $usuarios[1]]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$usuarios[0]->rut]])
            ->assertStatus(422)
            ->assertJsonPath('errors.ruts.0', 'La reserva necesita al menos 2 personas.');

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => $usuarios->pluck('rut')->all()])
            ->assertStatus(422)
            ->assertJsonPath('errors.ruts.0', 'La reserva admite como máximo 5 personas.');
    }

    public function test_no_se_puede_sumar_a_alguien_que_tiene_otra_sala_a_esa_hora(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto, $carla, $dani] = Usuario::factory()->count(4)->create();
        $reserva = $this->reserva([$ana, $beto]);
        // Carla y Dani ya están en otra sala, que se cruza con esta.
        $this->reserva([$carla, $dani], ['hora_inicio' => '13:30', 'hora_fin' => '14:30', 'hora_prestamo_real' => '2026-08-14 13:31:00']);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $carla->rut]])
            ->assertStatus(409)
            ->assertJsonPath('message', "El RUT {$carla->rut} ya tiene otra sala reservada en ese horario");
    }

    public function test_no_se_modifica_una_reserva_cancelada(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $reserva = $this->reserva([$ana, $beto], ['estado' => 'cancelada']);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $carla->rut]])->assertStatus(409);
    }

    public function test_pasado_el_plazo_sin_confirmar_no_se_modifica_y_la_sala_se_libera(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $reserva = $this->reserva([$ana, $beto]);
        Carbon::setTestNow('2026-08-14 14:30:00');

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $carla->rut]])->assertStatus(409);

        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_con_la_llegada_ya_confirmada_quien_se_suma_queda_presente(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $carla = Usuario::factory()->create(['nombre' => 'Carla', 'apellido' => 'Díaz']);
        $reserva = $this->reserva([$ana, $beto]);
        $this->patchJson("/api/reservas/{$reserva->id}/llegada")->assertOk();

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $beto->rut, $carla->rut]])
            ->assertOk()
            ->assertJsonPath('asistencia.registradas', ['Carla Díaz']);

        $this->assertDatabaseHas('entradas', ['usuario_id' => $carla->id, 'via' => 'sala', 'registrado_por_staff_id' => $staff->id]);
        $this->assertSame(1, Entrada::where('usuario_id', $ana->id)->count());
    }

    public function test_el_personal_sin_permiso_de_salas_no_modifica_integrantes(): void
    {
        Rol::create(['clave' => 'solo_entradas', 'nombre' => 'Solo entradas', 'permisos' => ['entradas.registrar']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'solo_entradas']));
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $reserva = $this->reserva([$ana, $beto]);

        $this->putJson("/api/reservas/{$reserva->id}/integrantes", ['ruts' => [$ana->rut, $carla->rut]])->assertForbidden();
    }
}
