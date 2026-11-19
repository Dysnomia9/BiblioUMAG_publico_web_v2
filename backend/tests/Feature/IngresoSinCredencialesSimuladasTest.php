<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IngresoSinCredencialesSimuladasTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_release_no_incluye_la_tabla_ni_el_comando_de_credenciales_simuladas(): void
    {
        $this->assertFalse(Schema::hasTable('credenciales_simuladas'));
        $this->assertArrayNotHasKey('login-v2:correos', Artisan::all());
    }

    public function test_credenciales_antiguas_no_entran_ni_reactivando_la_configuracion_anterior(): void
    {
        $staff = Staff::factory()->create(['email' => 'personal@umag.cl', 'password' => 'clave-real-personal']);
        $usuario = Usuario::factory()->create(['email' => 'usuario@umag.cl', 'password' => 'clave-real-usuario']);
        // Simula una base antigua antes de ejecutar la migración de retiro.
        (require database_path('migrations/2026_10_20_000001_create_credenciales_simuladas_table.php'))->up();
        foreach ([$staff, $usuario] as $cuenta) {
            DB::table('credenciales_simuladas')->insert([
                'cuenta_type' => $cuenta->getMorphClass(), 'cuenta_id' => $cuenta->id,
                'correo' => $cuenta->email, 'clave' => Hash::make('demo1234'),
            ]);
        }

        $this->app['env'] = 'local';
        config(['login_v2.correos_simulados' => true]);
        foreach ([$staff, $usuario] as $cuenta) {
            $this->postJson('/api/auth/ingresar', ['identificador' => $cuenta->email, 'password' => 'demo1234'])
                ->assertStatus(422);
        }
        $this->postJson('/api/auth/ingresar', ['identificador' => $staff->email, 'password' => 'clave-real-personal'])
            ->assertOk()->assertJsonPath('tipo', 'staff');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/ingresar', ['identificador' => $usuario->email, 'password' => 'clave-real-usuario'])
            ->assertOk()->assertJsonPath('tipo', 'usuario');
    }

    public function test_retirar_la_tabla_no_cambia_los_correos_ni_las_claves_reales(): void
    {
        $staff = Staff::factory()->create(['email' => 'personal@umag.cl']);
        $usuario = Usuario::factory()->create(['email' => 'usuario@umag.cl']);
        (require database_path('migrations/2026_10_20_000001_create_credenciales_simuladas_table.php'))->up();
        DB::table('credenciales_simuladas')->insert([
            'cuenta_type' => $staff->getMorphClass(), 'cuenta_id' => $staff->id,
            'correo' => 'simulado@umag.cl', 'clave' => Hash::make('demo1234'),
        ]);

        (require database_path('migrations/2026_11_19_000001_retirar_credenciales_simuladas.php'))->up();

        $this->assertFalse(Schema::hasTable('credenciales_simuladas'));
        foreach ([$staff, $usuario] as $cuenta) {
            $actual = $cuenta->fresh();
            $this->assertSame($cuenta->email, $actual->email);
            $this->assertSame($cuenta->password, $actual->password);
        }
    }
}
