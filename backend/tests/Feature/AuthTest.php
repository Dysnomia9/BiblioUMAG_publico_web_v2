<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_de_staff_con_credenciales_validas_devuelve_token(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token', 'staff']);
    }

    public function test_login_de_staff_con_password_incorrecta_devuelve_422(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'incorrecta',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_de_staff_inactivo_con_credenciales_correctas_devuelve_422_por_cuenta_inactiva(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123', 'activo' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_el_login_compara_la_clave_aunque_la_cuenta_no_exista(): void
    {
        // Así tarda lo mismo exista o no la cuenta, y no se puede averiguar por el tiempo
        // qué correos o RUT están registrados.
        $sinClave = Usuario::factory()->create(['password' => null]);
        // Envuelve el Hash real (make() sigue funcionando) y solo cuenta las comparaciones.
        $hash = Mockery::mock(Hash::getFacadeRoot());
        $hash->shouldReceive('check')->times(3)->andReturnFalse();
        Hash::swap($hash);

        $this->postJson('/api/auth/login', ['email' => 'nadie@umag.cl', 'password' => 'algo'])->assertStatus(422);
        $this->postJson('/api/auth/usuario/login', ['rut' => '15.000.000-9', 'password' => 'algo'])->assertStatus(422);
        $this->postJson('/api/auth/usuario/login', ['rut' => $sinClave->rut, 'password' => 'algo'])->assertStatus(422);
    }

    public function test_el_correo_del_personal_entra_con_o_sin_mayusculas(): void
    {
        Staff::factory()->create(['email' => 'Ana.Soto@UMAG.cl', 'password' => 'password123']);

        $this->assertDatabaseHas('staff', ['email' => 'ana.soto@umag.cl']);
        $this->postJson('/api/auth/login', ['email' => 'ANA.SOTO@umag.cl ', 'password' => 'password123'])->assertStatus(200);
        $this->postJson('/api/auth/login', ['email' => 'ana.soto@umag.cl', 'password' => 'password123'])->assertStatus(200);
    }

    public function test_los_correos_con_mayusculas_que_ya_existian_pasan_a_minuscula(): void
    {
        $migracion = require database_path('migrations/2026_10_23_000001_pasar_a_minuscula_los_correos_del_personal.php');
        // Escritos así antes de que el sistema los guardara en minúscula. En MySQL el índice
        // único ya no distingue mayúsculas: no puede haber dos que solo difieran en eso.
        $ana = Staff::factory()->create();
        $luis = Staff::factory()->create();
        DB::table('staff')->where('id', $ana->id)->update(['email' => 'Ana@umag.cl']);
        DB::table('staff')->where('id', $luis->id)->update(['email' => 'LUIS@UMAG.CL']);

        $migracion->up();

        $this->assertSame('ana@umag.cl', DB::table('staff')->where('id', $ana->id)->value('email'));
        $this->assertSame('luis@umag.cl', DB::table('staff')->where('id', $luis->id)->value('email'));
    }
}
