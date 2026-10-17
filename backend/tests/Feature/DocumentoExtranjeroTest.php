<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Documento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Estudiantes y visitantes extranjeros que se identifican con pasaporte o DNI en vez de RUT. */
class DocumentoExtranjeroTest extends TestCase
{
    use RefreshDatabase;

    private function extranjera(): Usuario
    {
        return Usuario::factory()->create([
            'rut' => '38456123', 'tipo_documento' => 'dni', 'pais' => 'Argentina',
            'nombre' => 'Martina', 'apellido' => 'Gómez', 'password' => 'umag1234',
        ]);
    }

    public function test_se_reconoce_el_tipo_de_documento(): void
    {
        $this->assertSame('rut', Documento::detectarTipo('15.003.151-6'));
        $this->assertSame('pasaporte', Documento::detectarTipo('ft 482913'));
        // Solo números y no es un RUT válido: se trata como RUT mal escrito (un DNI se declara).
        $this->assertSame('rut', Documento::detectarTipo('38.456.123'));
        $this->assertNotNull(Documento::validar('38.456.123', 'rut'));
        $this->assertNull(Documento::validar('38.456.123', 'dni'));
        $this->assertSame('FT482913', Documento::formatear('ft-482 913', 'pasaporte'));
        $this->assertSame('15.003.151-6', Documento::formatear('150031516', 'rut'));
        $this->assertNull(Documento::validar('AB12345', 'pasaporte'));
        $this->assertNotNull(Documento::validar('AB', 'pasaporte'));
    }

    public function test_el_mesón_registra_la_entrada_de_un_estudiante_con_dni_escrito_con_puntos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $martina = $this->extranjera();

        $this->postJson('/api/entrada', ['rut' => '38.456.123'])
            ->assertCreated()
            ->assertJsonPath('usuario.id', $martina->id)
            ->assertJsonPath('usuario.tipo_documento', 'dni');
    }

    public function test_el_portal_acepta_el_login_con_pasaporte(): void
    {
        Usuario::factory()->create(['rut' => 'FT482913', 'tipo_documento' => 'pasaporte', 'password' => 'umag1234']);

        $this->postJson('/api/auth/usuario/login', ['rut' => 'ft482913', 'password' => 'umag1234'])->assertOk();
    }

    public function test_un_estudiante_extranjero_puede_integrar_una_reserva_de_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $sala = Sala::factory()->create();
        $chileno = Usuario::factory()->create();
        $martina = $this->extranjera();
        $fecha = $this->fechaConAtencion(1);

        $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => $fecha,
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => [$chileno->rut, '38.456.123'],
        ])->assertCreated();

        $this->assertTrue(Reserva::sole()->participantes->contains($martina));
    }

    public function test_un_documento_que_no_es_de_nadie_marca_la_casilla_del_integrante(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $sala = Sala::factory()->create();
        $chileno = Usuario::factory()->create();

        $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => $this->fechaConAtencion(1),
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => [$chileno->rut, 'XX999999'],
        ])->assertStatus(422)->assertJsonValidationErrors('ruts.1');
    }

    public function test_un_visitante_externo_con_pasaporte_queda_con_su_tipo_y_pais(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/entrada/externo', [
            'rut' => 'ab 123 456', 'tipo_documento' => 'pasaporte', 'pais' => 'Perú', 'nombre' => 'Visitante',
        ])->assertCreated()
            ->assertJsonPath('rut_externo', 'AB123456')
            ->assertJsonPath('tipo_documento_externo', 'pasaporte')
            ->assertJsonPath('pais_externo', 'Perú');

        // El mismo pasaporte otra vez el mismo día: ya está presente.
        $this->postJson('/api/entrada/externo', ['rut' => 'AB-123456', 'tipo_documento' => 'pasaporte'])->assertStatus(409);
    }

    public function test_un_externo_con_rut_invalido_declarado_como_rut_se_rechaza(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/entrada/externo', ['rut' => '12.345.678-9', 'tipo_documento' => 'rut'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rut');
    }
}
