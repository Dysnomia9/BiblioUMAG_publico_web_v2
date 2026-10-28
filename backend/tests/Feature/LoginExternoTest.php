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

        $location = $this->volverDeGoogle()->assertRedirectContains('/login?codigo=')->headers->get('Location');

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

        $this->volverDeGoogle()->assertRedirectContains('/login?codigo=');
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

    public function test_google_pide_elegir_si_el_correo_es_del_personal_y_de_un_usuario(): void
    {
        $this->configurarGoogle();
        Staff::factory()->create(['email' => 'carla@umag.cl']);
        Usuario::factory()->create(['email' => 'carla@umag.cl', 'tipo' => 'estudiante', 'carrera' => 'Enfermería']);
        $this->googleDevuelve('carla@umag.cl');

        $location = $this->volverDeGoogle()->assertRedirectContains('/login?codigo=')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/google/canjear', ['codigo' => $query['codigo']])
            ->assertOk()
            ->assertJsonPath('elegir.0.tipo', 'staff')
            ->assertJsonPath('elegir.1.detalle', 'Estudiante · Enfermería')
            ->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // El mismo código sirve para elegir, y después ya no.
        $this->postJson('/api/auth/google/canjear', ['codigo' => $query['codigo'], 'como' => 'usuario'])
            ->assertOk()->assertJsonPath('tipo', 'usuario');
        $this->postJson('/api/auth/google/canjear', ['codigo' => $query['codigo'], 'como' => 'staff'])->assertStatus(422);
    }

    public function test_login_unificado_encuentra_las_cuentas_activas_de_un_correo(): void
    {
        $servicio = app(LoginUnificadoService::class);
        $staff = Staff::factory()->create(['email' => 'bibliotecaria@umag.cl']);
        $usuario = Usuario::factory()->create(['email' => 'alumno@umag.cl']);
        Staff::factory()->create(['email' => 'exempleado@umag.cl', 'activo' => false]);
        Usuario::factory()->create(['email' => 'exalumno@umag.cl', 'activo' => false]);

        $this->assertTrue($staff->is($servicio->cuentasPorEmail('Bibliotecaria@umag.cl')[0]));
        $this->assertTrue($usuario->is($servicio->cuentasPorEmail('alumno@umag.cl')[0]));
        $this->assertSame([], $servicio->cuentasPorEmail('nadie@umag.cl'));
        $this->assertSame([], $servicio->cuentasPorEmail('exempleado@umag.cl'));
        $this->assertSame([], $servicio->cuentasPorEmail('exalumno@umag.cl'));
    }

    public function test_login_unificado_entra_directo_con_una_cuenta_y_pregunta_con_dos(): void
    {
        $servicio = app(LoginUnificadoService::class);
        $staff = Staff::factory()->create(['email' => 'carla@umag.cl']);
        $usuario = Usuario::factory()->create(['email' => 'carla@umag.cl']);

        $this->assertSame('staff', $servicio->resolver([$staff], null, 'LDAP')['tipo']);
        $this->assertSame(['staff', 'usuario'], array_column($servicio->resolver([$staff, $usuario], null, 'LDAP')['elegir'], 'tipo'));
        $this->assertTrue($usuario->is($servicio->resolver([$staff, $usuario], 'usuario', 'LDAP')['entidad']));
        $this->assertNull($servicio->resolver([$usuario], 'staff', 'LDAP'));
    }
}
