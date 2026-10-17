<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LimiteSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_intentos_fallidos_de_una_cuenta_no_bloquean_el_login_de_otra_desde_la_misma_ip(): void
    {
        $atacada = Staff::factory()->create(['password' => 'password123']);
        $otra = Staff::factory()->create(['password' => 'password123']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['email' => $atacada->email, 'password' => 'incorrecta'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => $atacada->email, 'password' => 'password123'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiados intentos seguidos. Espera un minuto y vuelve a intentarlo.')
            ->assertHeader('Retry-After');
        $this->postJson('/api/auth/login', ['email' => $otra->email, 'password' => 'password123'])->assertOk();
    }

    public function test_el_limite_del_login_del_portal_cuenta_el_rut_con_o_sin_puntos_como_la_misma_cuenta(): void
    {
        $usuario = Usuario::factory()->create(['rut' => '15.003.151-6']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/usuario/login', ['rut' => '15.003.151-6', 'password' => 'incorrecta'])->assertStatus(422);
        }

        $this->postJson('/api/auth/usuario/login', ['rut' => '150031516', 'password' => 'password'])->assertStatus(429);
        $this->postJson('/api/auth/usuario/login', ['rut' => Usuario::factory()->create()->rut, 'password' => 'password'])->assertOk();
    }

    public function test_una_cuenta_del_meson_puede_hacer_mas_de_60_solicitudes_por_minuto(): void
    {
        $staff = Staff::factory()->asistente()->create();
        $token = $staff->createToken('test')->plainTextToken;

        for ($i = 0; $i < 80; $i++) {
            $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        }
    }

    public function test_el_personal_y_los_usuarios_con_el_mismo_id_no_comparten_el_limite(): void
    {
        $limite = RateLimiter::limiter('api');

        $pedidoStaff = Request::create('/api/auth/me');
        $pedidoStaff->setUserResolver(fn () => (new Staff)->forceFill(['id' => 5]));
        $pedidoUsuario = Request::create('/api/mi/multas');
        $pedidoUsuario->setUserResolver(fn () => (new Usuario)->forceFill(['id' => 5]));

        $this->assertNotSame($limite($pedidoStaff)->key, $limite($pedidoUsuario)->key);
    }
}
