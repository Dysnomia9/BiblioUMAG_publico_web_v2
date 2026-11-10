<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Último acceso» en Administración › Personal: queda en la cuenta al entrar y al salir, y no
 * se pierde cuando la sesión se borra (al cerrarla, al cambiar la contraseña o en la limpieza
 * nocturna de las vencidas).
 */
class PersonalUltimoAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ultimoAccesoDe(Staff $cuenta, Staff $admin): ?string
    {
        Sanctum::actingAs($admin);

        return collect($this->getJson('/api/personal')->assertOk()->json())->firstWhere('id', $cuenta->id)['ultimo_acceso'];
    }

    private function entrar(Staff $cuenta): string
    {
        return $this->postJson('/api/auth/login', ['email' => $cuenta->email, 'password' => 'password'])->assertOk()->json('token');
    }

    public function test_quien_nunca_entro_figura_sin_acceso(): void
    {
        $this->assertNull($this->ultimoAccesoDe(Staff::factory()->create(), Staff::factory()->admin()->create()));
    }

    public function test_el_acceso_queda_aunque_cierre_sesion(): void
    {
        $admin = Staff::factory()->admin()->create();
        $cuenta = Staff::factory()->create();
        $editada = $cuenta->updated_at;

        Carbon::setTestNow('2026-11-10 09:15:00');
        $token = $this->entrar($cuenta);
        $this->assertSame('2026-11-10T09:15:00-03:00', $this->ultimoAccesoDe($cuenta, $admin));
        // Entrar no es editar la cuenta.
        $this->assertEquals($editada, $cuenta->fresh()->updated_at);

        // Sale con su propia sesión (no con la del administrador que miró la lista).
        Carbon::setTestNow('2026-11-10 11:40:00');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, $cuenta->tokens()->count());

        $this->withoutToken();
        $this->app['auth']->forgetGuards();
        $this->assertSame('2026-11-10T11:40:00-03:00', $this->ultimoAccesoDe($cuenta, $admin));
    }

    public function test_el_acceso_queda_aunque_se_borren_las_sesiones_vencidas(): void
    {
        $admin = Staff::factory()->admin()->create();
        $cuenta = Staff::factory()->create();

        Carbon::setTestNow('2026-11-08 18:05:00');
        $this->entrar($cuenta);
        // Como sanctum:prune-expired a la noche siguiente.
        $cuenta->tokens()->delete();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->assertSame('2026-11-08T18:05:00-03:00', $this->ultimoAccesoDe($cuenta, $admin));
    }

    public function test_si_sigue_usando_el_panel_cuenta_lo_ultimo_que_hizo(): void
    {
        $admin = Staff::factory()->admin()->create();
        $cuenta = Staff::factory()->create();

        Carbon::setTestNow('2026-11-10 08:50:00');
        $this->entrar($cuenta);
        $cuenta->tokens()->update(['last_used_at' => '2026-11-10 12:30:00']);

        $this->assertSame('2026-11-10T12:30:00-03:00', $this->ultimoAccesoDe($cuenta, $admin));
    }
}
