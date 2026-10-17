<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrearAdministradorTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_una_cuenta_con_acceso_total_que_puede_iniciar_sesion(): void
    {
        $this->artisan('personal:crear-admin', [
            'email' => 'Directora@UMAG.cl',
            '--nombre' => 'Adriana Navarro',
            '--password' => 'clave-segura-1',
        ])->assertSuccessful();

        $staff = Staff::where('email', 'directora@umag.cl')->firstOrFail();
        $this->assertSame('admin', $staff->rol);
        $this->assertTrue($staff->activo);
        $this->assertTrue($staff->puede('personal.gestionar'));

        $this->postJson('/api/auth/login', ['email' => 'directora@umag.cl', 'password' => 'clave-segura-1'])->assertOk();
    }

    public function test_si_no_se_indica_contrasena_genera_una_y_la_muestra_una_vez(): void
    {
        $this->artisan('personal:crear-admin', ['email' => 'jefa@umag.cl', '--nombre' => 'Jefa de Unidad'])
            ->expectsOutputToContain('Contraseña generada:')
            ->assertSuccessful();

        $this->assertDatabaseHas('staff', ['email' => 'jefa@umag.cl', 'rol' => 'admin']);
    }

    public function test_promueve_y_reactiva_una_cuenta_existente_y_cierra_sus_sesiones(): void
    {
        $staff = Staff::factory()->asistente()->create(['email' => 'pedro@umag.cl', 'activo' => false]);
        $staff->createToken('sesion-vieja');

        $this->artisan('personal:crear-admin', ['email' => 'pedro@umag.cl', '--password' => 'otra-clave-99'])->assertSuccessful();

        $staff->refresh();
        $this->assertSame('admin', $staff->rol);
        $this->assertTrue($staff->activo);
        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_rechaza_una_contrasena_corta_o_una_cuenta_nueva_sin_nombre(): void
    {
        $this->artisan('personal:crear-admin', ['email' => 'a@umag.cl', '--nombre' => 'A', '--password' => 'corta'])->assertFailed();
        $this->artisan('personal:crear-admin', ['email' => 'b@umag.cl', '--password' => 'suficientemente-larga'])->assertFailed();

        $this->assertDatabaseMissing('staff', ['email' => 'a@umag.cl']);
        $this->assertDatabaseMissing('staff', ['email' => 'b@umag.cl']);
    }

    public function test_los_datos_de_prueba_no_se_cargan_en_produccion(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('mockup:datos')->assertFailed();

        $this->assertDatabaseMissing('staff', ['email' => 'admin@umag.cl']);
    }
}
