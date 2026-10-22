<?php

namespace Tests\Feature;

use App\Models\CredencialSimulada;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Login v2: solo con correo, usando los correos y la clave simulados (config/login_v2.php). */
class LoginV2SimuladoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['login_v2.correos_simulados' => true]);
    }

    public function test_cada_cuenta_recibe_un_correo_con_sus_iniciales_sin_tocar_el_real(): void
    {
        $admin = Staff::factory()->create(['nombre' => 'Ignacio Contreras', 'email' => 'admin@umag.cl']);
        $diego = Usuario::factory()->create(['nombre' => 'Diego', 'apellido' => 'Muñoz Pérez', 'email' => 'diego.muñoz0@umag.cl']);
        $otroDiego = Usuario::factory()->create(['nombre' => 'Diana', 'apellido' => 'Muñoz', 'email' => null]);
        $sinCorreo = Usuario::factory()->create(['nombre' => 'José', 'apellido' => 'Ñúñez', 'email' => null]);

        $this->artisan('login-v2:correos')->assertSuccessful();

        $correo = fn ($cuenta) => CredencialSimulada::whereMorphedTo('cuenta', $cuenta)->value('correo');
        $this->assertSame('igcontre@umag.cl', $correo($admin));
        $this->assertSame('dimunoz@umag.cl', $correo($diego));
        $this->assertSame('dimunoz2@umag.cl', $correo($otroDiego));
        $this->assertSame('jonunez@umag.cl', $correo($sinCorreo));

        // Los correos reales no cambian, y correrlo otra vez no duplica ni reasigna.
        $this->assertSame('admin@umag.cl', $admin->fresh()->email);
        $this->assertNull($sinCorreo->fresh()->email);
        $this->artisan('login-v2:correos')->assertSuccessful();
        $this->assertDatabaseCount('credenciales_simuladas', 4);
        $this->assertSame('dimunoz2@umag.cl', $correo($otroDiego));
    }

    public function test_el_personal_y_los_usuarios_entran_con_su_correo_simulado(): void
    {
        Staff::factory()->create(['nombre' => 'Ignacio Contreras', 'email' => 'admin@umag.cl', 'password' => 'admin123']);
        Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto', 'rut' => '11.111.111-1', 'password' => 'umag123']);
        $this->artisan('login-v2:correos')->assertSuccessful();

        $staff = $this->postJson('/api/auth/v2/login', ['correo' => 'IGContre@umag.cl', 'password' => 'demo1234'])
            ->assertOk()->assertJsonPath('tipo', 'staff');
        $this->withToken($staff->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('nombre', 'Ignacio Contreras');

        $this->app['auth']->forgetGuards();
        $usuario = $this->postJson('/api/auth/v2/login', ['correo' => 'vasoto@umag.cl', 'password' => 'demo1234'])
            ->assertOk()->assertJsonPath('tipo', 'usuario');
        $this->app['auth']->forgetGuards();
        $this->withToken($usuario->json('token'))->getJson('/api/auth/usuario/me')->assertOk()->assertJsonPath('rut', '11.111.111-1');

        // Las credenciales de siempre siguen funcionando donde estaban.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'admin@umag.cl', 'password' => 'admin123'])->assertOk();
        $this->postJson('/api/auth/usuario/login', ['rut' => '11.111.111-1', 'password' => 'umag123'])->assertOk();
    }

    public function test_tambien_se_entra_con_el_correo_y_la_contrasena_reales(): void
    {
        // Como la cuenta que deja sistema:iniciar --vaciar: sin correo simulado.
        Staff::factory()->create(['nombre' => 'Administrador general', 'email' => 'admin@umag.cl', 'password' => 'clave-al-azar']);
        Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto', 'email' => 'valentina.soto@umag.cl', 'password' => 'umag123']);

        $this->postJson('/api/auth/v2/login', ['correo' => 'Admin@umag.cl', 'password' => 'clave-al-azar'])
            ->assertOk()->assertJsonPath('tipo', 'staff');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/v2/login', ['correo' => 'valentina.soto@umag.cl', 'password' => 'umag123'])
            ->assertOk()->assertJsonPath('tipo', 'usuario');

        // La clave de prueba no sirve con el correo real, y el simulado sigue con la suya.
        $this->postJson('/api/auth/v2/login', ['correo' => 'admin@umag.cl', 'password' => 'demo1234'])
            ->assertStatus(422)->assertJsonPath('errors.correo.0', 'El correo o la contraseña no coinciden.');
        $this->artisan('login-v2:correos')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/v2/login', ['correo' => 'vasoto@umag.cl', 'password' => 'demo1234'])
            ->assertOk()->assertJsonPath('tipo', 'usuario');
    }

    public function test_si_el_correo_simulado_es_el_real_vale_cualquiera_de_las_dos_claves(): void
    {
        Staff::factory()->create(['nombre' => 'Ignacio Contreras', 'email' => 'igcontre@umag.cl', 'password' => 'clave-propia']);
        $this->artisan('login-v2:correos')->assertSuccessful();

        foreach (['clave-propia', 'demo1234'] as $clave) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/v2/login', ['correo' => 'igcontre@umag.cl', 'password' => $clave])
                ->assertOk()->assertJsonPath('tipo', 'staff');
        }
    }

    public function test_el_login_v2_no_acepta_rut_ni_clave_equivocada_ni_cuentas_inactivas(): void
    {
        Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto', 'rut' => '11.111.111-1', 'password' => 'umag123']);
        Usuario::factory()->create(['nombre' => 'Inés', 'apellido' => 'Activa', 'email' => 'ines@umag.cl', 'password' => 'umag123', 'activo' => false]);
        $this->artisan('login-v2:correos')->assertSuccessful();

        $this->postJson('/api/auth/v2/login', ['correo' => '11.111.111-1', 'password' => 'umag123'])
            ->assertStatus(422)->assertJsonValidationErrors('correo');
        $this->postJson('/api/auth/v2/login', ['correo' => 'vasoto@umag.cl', 'password' => 'umag123'])
            ->assertStatus(422)->assertJsonPath('errors.correo.0', 'El correo o la contraseña no coinciden.');
        $this->postJson('/api/auth/v2/login', ['correo' => 'inactiva@umag.cl', 'password' => 'demo1234'])
            ->assertStatus(422)->assertJsonPath('errors.correo.0', 'Tu cuenta está inactiva. Contacta a la biblioteca.');
        $this->postJson('/api/auth/v2/login', ['correo' => 'ines@umag.cl', 'password' => 'umag123'])
            ->assertStatus(422)->assertJsonPath('errors.correo.0', 'Tu cuenta está inactiva. Contacta a la biblioteca.');
        $this->postJson('/api/auth/v2/login', ['correo' => 'nadie@umag.cl', 'password' => 'demo1234'])
            ->assertStatus(422)->assertJsonPath('errors.correo.0', 'El correo o la contraseña no coinciden.');
    }

    public function test_apagado_no_deja_entrar(): void
    {
        Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto']);
        $this->artisan('login-v2:correos')->assertSuccessful();
        config(['login_v2.correos_simulados' => false]);

        $this->postJson('/api/auth/v2/login', ['correo' => 'vasoto@umag.cl', 'password' => 'demo1234'])->assertNotFound();
    }
}
