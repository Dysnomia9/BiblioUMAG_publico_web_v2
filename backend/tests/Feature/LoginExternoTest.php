<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use App\Services\LoginUnificadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre lo que se puede probar sin depender de un servidor LDAP/Google real:
 * los gates de "no configurado" (503 en vez de intentar conectar) y el servicio
 * compartido que mapea un email externo a Staff/Usuario. El flujo de bind LDAP
 * real (search + bind, mapeo de atributos) se verificó manualmente el
 * 2026-08-20 contra un servidor OpenLDAP de prueba — ver CLAUDE.md.
 */
class LoginExternoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ldap_login_devuelve_503_sin_ldap_host_configurado(): void
    {
        config(['ldap.connections.default.hosts' => []]);

        $response = $this->postJson('/api/auth/ldap/login', ['usuario' => 'jperez', 'password' => 'cualquiera']);

        $response->assertStatus(503);
    }

    public function test_google_redirect_manda_de_vuelta_al_frontend_sin_client_id_configurado(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->get('/api/auth/google/redirect');

        $response->assertStatus(302);
        $response->assertRedirectContains('error=google_no_configurado');
    }

    public function test_google_callback_manda_de_vuelta_al_frontend_sin_client_id_configurado(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->get('/api/auth/google/callback');

        $response->assertStatus(302);
        $response->assertRedirectContains('error=google_no_configurado');
    }

    public function test_login_unificado_mapea_a_staff_por_email(): void
    {
        $staff = Staff::factory()->create(['email' => 'bibliotecaria@umag.cl']);

        $resultado = app(LoginUnificadoService::class)->porEmail('bibliotecaria@umag.cl');

        $this->assertSame('staff', $resultado['tipo']);
        $this->assertNotEmpty($resultado['token']);
        $this->assertTrue($staff->is($resultado['entidad']));
    }

    public function test_login_unificado_mapea_a_usuario_por_email_si_no_hay_staff_con_ese_email(): void
    {
        $usuario = Usuario::factory()->create(['email' => 'alumno@umag.cl']);

        $resultado = app(LoginUnificadoService::class)->porEmail('alumno@umag.cl');

        $this->assertSame('usuario', $resultado['tipo']);
        $this->assertTrue($usuario->is($resultado['entidad']));
    }

    public function test_login_unificado_devuelve_null_si_ningun_email_coincide(): void
    {
        $resultado = app(LoginUnificadoService::class)->porEmail('nadie@umag.cl');

        $this->assertNull($resultado);
    }

    public function test_login_unificado_devuelve_null_si_el_staff_esta_inactivo(): void
    {
        Staff::factory()->create(['email' => 'exempleado@umag.cl', 'activo' => false]);

        $resultado = app(LoginUnificadoService::class)->porEmail('exempleado@umag.cl');

        $this->assertNull($resultado);
    }

    public function test_login_unificado_devuelve_null_si_el_usuario_esta_inactivo(): void
    {
        Usuario::factory()->create(['email' => 'exalumno@umag.cl', 'activo' => false]);

        $resultado = app(LoginUnificadoService::class)->porEmail('exalumno@umag.cl');

        $this->assertNull($resultado);
    }
}
