<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El login único (/auth/ingresar): correo y contraseña para el personal y los usuarios (con
 * el RUT no se entra), y la pregunta de con qué cuenta entrar cuando la persona tiene las dos.
 */
class IngresoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Solo las credenciales reales: los correos simulados son otra cosa (CredencialesSimuladasTest).
        config(['login_v2.correos_simulados' => false]);
    }

    private function ingresar(string $identificador, string $password, ?string $como = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/ingresar', array_filter(['identificador' => $identificador, 'password' => $password, 'como' => $como]));
    }

    public function test_el_personal_entra_con_su_correo_y_queda_en_la_bitacora(): void
    {
        Staff::factory()->create(['nombre' => 'Marta Mesón', 'email' => 'meson@umag.cl', 'password' => 'meson123']);

        $token = $this->ingresar('Meson@umag.cl', 'meson123')->assertOk()->assertJsonPath('tipo', 'staff')->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('nombre', 'Marta Mesón');
        $this->assertSame('Inició sesión en el panel del personal', Bitacora::where('accion', 'sesion.ingresar')->sole()->descripcion);
    }

    public function test_un_usuario_entra_con_su_correo_o_uno_adicional(): void
    {
        $valentina = Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => 'valentina.soto@umag.cl', 'password' => 'umag123']);
        $valentina->correos()->create(['email' => 'vsoto@alumnos.umag.cl']);

        foreach (['valentina.soto@umag.cl', 'VSoto@alumnos.umag.cl'] as $identificador) {
            $this->ingresar($identificador, 'umag123')->assertOk()->assertJsonPath('tipo', 'usuario');
        }

        $this->ingresar('valentina.soto@umag.cl', 'otra-clave')
            ->assertStatus(422)->assertJsonPath('errors.identificador.0', 'El correo y la contraseña no coinciden.');
        $this->ingresar('nadie@umag.cl', 'umag123')->assertStatus(422);
    }

    public function test_con_el_rut_o_el_pasaporte_no_se_entra(): void
    {
        Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => 'valentina.soto@umag.cl', 'password' => 'umag123']);
        Usuario::factory()->create(['rut' => 'FT482913', 'tipo_documento' => 'pasaporte', 'pais' => 'Brasil', 'email' => null, 'password' => 'umag123']);

        foreach (['11.111.111-1', '111111111', 'ft482913'] as $identificador) {
            $this->ingresar($identificador, 'umag123')
                ->assertStatus(422)
                ->assertJsonPath('errors.identificador.0', 'Se entra con el correo institucional (por ejemplo, nombre@umag.cl), no con el RUT.');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_con_cuenta_del_personal_y_de_usuario_pregunta_con_cual_entrar(): void
    {
        Staff::factory()->create(['email' => 'carla@umag.cl', 'password' => 'misma-clave']);
        Usuario::factory()->create(['rut' => '22.222.222-2', 'email' => 'carla@umag.cl', 'password' => 'misma-clave', 'tipo' => 'estudiante', 'carrera' => 'Enfermería']);

        $this->ingresar('carla@umag.cl', 'misma-clave')
            ->assertOk()
            ->assertJsonPath('elegir.0.tipo', 'staff')
            ->assertJsonPath('elegir.0.titulo', 'Panel del personal')
            ->assertJsonPath('elegir.1.tipo', 'usuario')
            ->assertJsonPath('elegir.1.detalle', 'Estudiante · Enfermería')
            ->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->ingresar('carla@umag.cl', 'misma-clave', 'usuario')->assertOk()->assertJsonPath('tipo', 'usuario');
        $this->ingresar('carla@umag.cl', 'misma-clave', 'staff')->assertOk()->assertJsonPath('tipo', 'staff');
    }

    public function test_si_cada_cuenta_tiene_su_clave_la_clave_decide(): void
    {
        Staff::factory()->create(['email' => 'carla@umag.cl', 'password' => 'clave-del-panel']);
        Usuario::factory()->create(['email' => 'carla@umag.cl', 'password' => 'clave-del-portal']);

        $this->ingresar('carla@umag.cl', 'clave-del-panel')->assertOk()->assertJsonPath('tipo', 'staff');
        $this->ingresar('carla@umag.cl', 'clave-del-portal')->assertOk()->assertJsonPath('tipo', 'usuario');
        $this->ingresar('carla@umag.cl', 'clave-del-portal', 'staff')
            ->assertStatus(422)->assertJsonPath('errors.identificador.0', 'Esa cuenta no está disponible con estos datos.');
    }

    public function test_una_cuenta_inactiva_lo_dice_y_si_la_otra_sirve_entra_con_esa(): void
    {
        Usuario::factory()->create(['email' => 'vencida@umag.cl', 'password' => 'umag123', 'vigente_hasta' => '2025-03-31']);
        Staff::factory()->create(['email' => 'exmeson@umag.cl', 'password' => 'meson123', 'activo' => false]);
        Staff::factory()->create(['email' => 'carla@umag.cl', 'password' => 'misma-clave']);
        Usuario::factory()->create(['email' => 'carla@umag.cl', 'password' => 'misma-clave', 'activo' => false]);

        $this->ingresar('vencida@umag.cl', 'umag123')
            ->assertStatus(422)->assertJsonPath('errors.identificador.0', 'Tu cuenta venció el 31/03/2025. Acércate a biblioteca para renovarla.');
        $this->ingresar('exmeson@umag.cl', 'meson123')
            ->assertStatus(422)->assertJsonPath('errors.identificador.0', 'Tu cuenta se encuentra inactiva. Contacta a un administrador.');
        $this->ingresar('carla@umag.cl', 'misma-clave')->assertOk()->assertJsonPath('tipo', 'staff');
    }
}
