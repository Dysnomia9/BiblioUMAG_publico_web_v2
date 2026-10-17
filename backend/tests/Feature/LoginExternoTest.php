<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use App\Services\LoginUnificadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as CuentaGoogle;
use Mockery;
use Tests\TestCase;

/**
 * Cubre lo que se puede probar sin depender de un servidor LDAP/Google real:
 * los gates de "no configurado" (503 en vez de intentar conectar), el servicio
 * compartido que mapea un email externo a Staff/Usuario y la vuelta de Google con
 * la respuesta de Google simulada. El flujo de bind LDAP real (search + bind, mapeo
 * de atributos) se verificó manualmente el 2026-08-20 contra un servidor OpenLDAP
 * de prueba.
 */
class LoginExternoTest extends TestCase
{
    use RefreshDatabase;

    private function configurarGoogle(array $extra = []): void
    {
        config(['services.google' => [
            'client_id' => 'cliente-de-prueba',
            'client_secret' => 'secreto-de-prueba',
            'redirect' => 'http://localhost:8000/api/auth/google/callback',
            'dominios' => [],
            ...$extra,
        ]]);
    }

    /** Lo que Google contestaría por esa cuenta, sin contactar a Google. */
    private function googleDevuelve(string $email, array $datos = ['email_verified' => true]): void
    {
        $cuenta = (new CuentaGoogle)->setRaw(['email' => $email, ...$datos])->map(['email' => $email]);
        $proveedor = Mockery::mock();
        $proveedor->shouldReceive('stateless')->andReturnSelf();
        $proveedor->shouldReceive('user')->andReturn($cuenta);
        Socialite::shouldReceive('driver')->with('google')->andReturn($proveedor);
    }

    private function volverDeGoogle(string $state = 'state-de-prueba')
    {
        return $this->withUnencryptedCookie('google_oauth_state', 'state-de-prueba')
            ->get("/api/auth/google/callback?state={$state}&code=codigo-de-google");
    }

    public function test_google_redirect_guarda_el_state_en_una_cookie_y_se_lo_manda_a_google(): void
    {
        $this->configurarGoogle();

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirectContains('accounts.google.com');
        $state = $response->getCookie('google_oauth_state', false)?->getValue();
        $this->assertNotEmpty($state);
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state']);
    }

    public function test_google_callback_rechaza_un_state_distinto_al_de_la_cookie(): void
    {
        $this->configurarGoogle();
        Staff::factory()->create(['email' => 'bibliotecaria@umag.cl']);
        $this->googleDevuelve('bibliotecaria@umag.cl');

        $this->volverDeGoogle(state: 'otro-state')->assertRedirectContains('error=google_fallo');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_vuelve_con_un_codigo_de_un_solo_uso_y_no_con_el_token(): void
    {
        $this->configurarGoogle();
        Staff::factory()->create(['email' => 'bibliotecaria@umag.cl']);
        $this->googleDevuelve('bibliotecaria@umag.cl');

        $location = $this->volverDeGoogle()->assertRedirectContains('/login/v2?codigo=')->headers->get('Location');

        $this->assertStringNotContainsString('token', $location);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->postJson('/api/auth/google/canjear', ['codigo' => $query['codigo']])
            ->assertOk()
            ->assertJsonPath('tipo', 'staff')
            ->assertJsonStructure(['token']);
        $this->postJson('/api/auth/google/canjear', ['codigo' => $query['codigo']])->assertStatus(422);
    }

    public function test_google_rechaza_un_correo_que_google_no_verifico(): void
    {
        $this->configurarGoogle();
        Staff::factory()->create(['email' => 'bibliotecaria@umag.cl']);
        $this->googleDevuelve('bibliotecaria@umag.cl', ['email_verified' => false]);

        $this->volverDeGoogle()->assertRedirectContains('error=google_no_permitido');
    }

    public function test_google_exige_uno_de_los_dominios_configurados(): void
    {
        $this->configurarGoogle(['dominios' => ['umag.cl']]);
        Usuario::factory()->create(['email' => 'alumno@gmail.com']);
        // Una cuenta personal de Gmail no trae hd (dominio de Workspace).
        $this->googleDevuelve('alumno@gmail.com');

        $this->volverDeGoogle()->assertRedirectContains('error=google_no_permitido');
    }

    public function test_google_acepta_una_cuenta_del_dominio_configurado(): void
    {
        $this->configurarGoogle(['dominios' => ['umag.cl']]);
        Usuario::factory()->create(['email' => 'alumno@umag.cl']);
        $this->googleDevuelve('alumno@umag.cl', ['email_verified' => true, 'hd' => 'umag.cl']);

        $this->volverDeGoogle()->assertRedirectContains('/login/v2?codigo=');
    }

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
