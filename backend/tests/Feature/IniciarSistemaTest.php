<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\VaciarBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * sistema:iniciar deja el sistema sin datos de prueba, con una sola cuenta: la del
 * administrador general, con una contraseña al azar que se muestra una vez.
 */
class IniciarSistemaTest extends TestCase
{
    use RefreshDatabase;

    /** Reemplaza el vaciado real (migrate:fresh) por uno que borra los usuarios y avisa que corrió. */
    private function vaciadoSimulado(): object
    {
        $vaciado = new class extends VaciarBase
        {
            public bool $corrio = false;

            public function __invoke(): void
            {
                $this->corrio = true;
                Usuario::query()->delete();
            }
        };
        $this->app->instance(VaciarBase::class, $vaciado);

        return $vaciado;
    }

    public function test_en_una_base_nueva_crea_solo_el_administrador_general_y_su_clave_sirve_para_entrar(): void
    {
        $this->assertSame(0, Artisan::call('sistema:iniciar'));
        preg_match('/Contraseña generada: (\S+)/', Artisan::output(), $clave);

        $admin = Staff::sole();
        $this->assertSame('admin@umag.cl', $admin->email);
        $this->assertSame('Administrador general', $admin->nombre);
        $this->assertTrue($admin->puede('personal.gestionar'));
        $this->postJson('/api/auth/login', ['email' => 'admin@umag.cl', 'password' => $clave[1] ?? ''])->assertOk();

        // Lo que crean las migraciones sigue ahí: roles y salas.
        $this->assertSame(3, Rol::count());
        $this->assertSame(18, Sala::count());
    }

    public function test_se_puede_elegir_otro_correo_y_nombre(): void
    {
        $this->artisan('sistema:iniciar', ['email' => 'jefa@umag.cl', '--nombre' => 'Jefa de Unidad'])->assertSuccessful();

        $this->assertDatabaseHas('staff', ['email' => 'jefa@umag.cl', 'nombre' => 'Jefa de Unidad', 'rol' => 'admin']);
    }

    public function test_si_la_base_ya_tiene_datos_no_hace_nada_sin_vaciar(): void
    {
        $vaciado = $this->vaciadoSimulado();
        Usuario::factory()->count(2)->create();

        $this->artisan('sistema:iniciar')
            ->expectsOutputToContain('La base ya tiene datos (2 usuarios)')
            ->assertFailed();

        $this->assertFalse($vaciado->corrio);
        $this->assertSame(0, Staff::count());
    }

    public function test_vaciar_no_borra_nada_si_no_se_escribe_vaciar(): void
    {
        $vaciado = $this->vaciadoSimulado();
        Usuario::factory()->create();

        $this->artisan('sistema:iniciar', ['--vaciar' => true])
            ->expectsQuestion('Para confirmar, escribe VACIAR', 'si')
            ->expectsOutputToContain('No se borró nada.')
            ->assertFailed();

        $this->assertFalse($vaciado->corrio);
        $this->assertSame(1, Usuario::count());
    }

    public function test_vaciar_confirmado_borra_y_deja_solo_el_administrador_general(): void
    {
        $vaciado = $this->vaciadoSimulado();
        Usuario::factory()->create();

        $this->artisan('sistema:iniciar', ['--vaciar' => true])
            ->expectsQuestion('Para confirmar, escribe VACIAR', 'VACIAR')
            ->expectsOutputToContain('Contraseña generada:')
            ->assertSuccessful();

        $this->assertTrue($vaciado->corrio);
        $this->assertSame(['admin@umag.cl'], Staff::pluck('email')->all());
    }

    public function test_en_produccion_vaciar_no_acepta_force(): void
    {
        $vaciado = $this->vaciadoSimulado();
        Usuario::factory()->create();
        $this->app['env'] = 'production';

        $this->artisan('sistema:iniciar', ['--vaciar' => true, '--force' => true])
            ->expectsOutputToContain('no se acepta --force')
            ->assertFailed();

        $this->assertFalse($vaciado->corrio);
    }
}
