<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use App\Models\UsuarioCorreo;
use App\Services\LoginUnificadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Un usuario puede tener más de un correo: el principal y los adicionales (+1). */
class UsuarioCorreosTest extends TestCase
{
    use RefreshDatabase;

    private function comoAdmin(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
    }

    public function test_la_carga_masiva_suma_un_segundo_correo_en_vez_de_reemplazar_el_que_ya_tenia(): void
    {
        $this->comoAdmin();
        $usuario = Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => 'principal@umag.cl']);

        $revision = $this->postJson('/api/usuarios/importar/revisar', ['filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'Personal@Gmail.com'],
        ]])->assertOk();
        $revision->assertJsonPath('filas.0.estado', 'actualizar')
            ->assertJsonPath('filas.0.cambios.correos_adicionales', [null, ['personal@gmail.com']])
            ->assertJsonMissingPath('filas.0.cambios.email');

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'Personal@Gmail.com'],
        ]])->assertCreated();

        $usuario->refresh();
        $this->assertSame('principal@umag.cl', $usuario->email);
        $this->assertSame(['personal@gmail.com'], $usuario->correos->pluck('email')->all());

        // Cargar lo mismo otra vez ya no cambia nada.
        $this->postJson('/api/usuarios/importar/revisar', ['filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'personal@gmail.com'],
        ]])->assertOk()->assertJsonPath('filas.0.estado', 'sin_cambios');
    }

    public function test_si_no_tenia_correo_el_de_la_planilla_queda_como_principal(): void
    {
        $this->comoAdmin();
        $usuario = Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => null]);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'nuevo@umag.cl'],
        ]])->assertCreated();

        $this->assertSame('nuevo@umag.cl', $usuario->fresh()->email);
        $this->assertCount(0, $usuario->fresh()->correos);
    }

    public function test_un_correo_que_ya_usa_otra_persona_como_adicional_no_se_agrega(): void
    {
        $this->comoAdmin();
        $otra = Usuario::factory()->create(['email' => 'otra@umag.cl']);
        UsuarioCorreo::create(['usuario_id' => $otra->id, 'email' => 'compartido@gmail.com']);
        Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => 'principal@umag.cl']);

        $this->postJson('/api/usuarios/importar/revisar', ['filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'compartido@gmail.com'],
        ]])->assertOk()
            ->assertJsonPath('filas.0.estado', 'sin_cambios')
            ->assertJsonPath('filas.0.mensajes.1', fn ($m) => str_contains($m, 'ya lo usa'));
    }

    public function test_se_pueden_editar_los_correos_adicionales_a_mano(): void
    {
        $this->comoAdmin();
        $usuario = Usuario::factory()->create(['email' => 'principal@umag.cl']);
        $otra = Usuario::factory()->create(['email' => 'ajeno@umag.cl']);

        $cuerpo = ['tipo_documento' => 'rut', 'rut' => $usuario->rut, 'nombre' => $usuario->nombre, 'apellido' => $usuario->apellido, 'tipo' => 'estudiante', 'email' => 'principal@umag.cl'];

        $this->putJson("/api/usuarios/{$usuario->id}", $cuerpo + ['correos_adicionales' => ['Casa@Gmail.com', 'trabajo@empresa.cl', 'casa@gmail.com', 'principal@umag.cl']])
            ->assertOk()
            ->assertJsonCount(2, 'correos');
        $this->assertEqualsCanonicalizing(['casa@gmail.com', 'trabajo@empresa.cl'], $usuario->fresh()->correos->pluck('email')->all());

        // Quitar uno y no tocar nada cuando el campo no viene.
        $this->putJson("/api/usuarios/{$usuario->id}", $cuerpo + ['correos_adicionales' => ['casa@gmail.com']])->assertOk();
        $this->assertSame(['casa@gmail.com'], $usuario->fresh()->correos->pluck('email')->all());
        $this->putJson("/api/usuarios/{$usuario->id}", $cuerpo)->assertOk();
        $this->assertSame(['casa@gmail.com'], $usuario->fresh()->correos->pluck('email')->all());

        // El correo de otra persona, principal o adicional, no se puede usar.
        $this->putJson("/api/usuarios/{$usuario->id}", $cuerpo + ['correos_adicionales' => ['ajeno@umag.cl']])
            ->assertStatus(422)->assertJsonValidationErrors(['correos_adicionales']);
        $this->putJson("/api/usuarios/{$otra->id}", ['tipo_documento' => 'rut', 'rut' => $otra->rut, 'nombre' => $otra->nombre, 'apellido' => $otra->apellido, 'tipo' => 'estudiante', 'email' => 'casa@gmail.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_el_ingreso_con_google_o_el_directorio_reconoce_cualquiera_de_los_correos(): void
    {
        $usuario = Usuario::factory()->create(['email' => 'principal@umag.cl', 'activo' => true]);
        UsuarioCorreo::create(['usuario_id' => $usuario->id, 'email' => 'personal@gmail.com']);

        $servicio = app(LoginUnificadoService::class);
        $this->assertSame($usuario->id, $servicio->cuentaPorEmail('principal@umag.cl')?->id);
        $this->assertSame($usuario->id, $servicio->cuentaPorEmail('Personal@GMAIL.com')?->id);
        $this->assertNull($servicio->cuentaPorEmail('desconocido@gmail.com'));
    }

    public function test_el_listado_busca_tambien_por_correos_adicionales_y_los_trae(): void
    {
        $this->comoAdmin();
        $usuario = Usuario::factory()->create(['email' => 'principal@umag.cl']);
        UsuarioCorreo::create(['usuario_id' => $usuario->id, 'email' => 'secreto@gmail.com']);
        Usuario::factory()->create();

        $this->getJson('/api/usuarios?q=secreto@gmail')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $usuario->id)
            ->assertJsonPath('data.0.correos.0.email', 'secreto@gmail.com');
    }
}
