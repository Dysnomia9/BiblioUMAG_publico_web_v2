<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Usuarios activos» son las cuentas que sirven hoy, como en Usuario::scopeVigentes(): una
 * vencida guarda activo = true y su fecha ya pasada, como las que vienen de Horizon.
 */
class DashboardResumenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_usuarios_activos_no_cuenta_las_vencidas_ni_las_desactivadas(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        Usuario::factory()->create(['activo' => true, 'vigente_hasta' => '2027-03-31']);
        Usuario::factory()->create(['activo' => true, 'vigente_hasta' => '2026-08-14']);   // vence hoy: todavía sirve
        Usuario::factory()->create(['activo' => true, 'vigente_hasta' => null]);           // no vence
        Usuario::factory()->create(['activo' => true, 'vigente_hasta' => '2021-03-15']);   // vencida
        Usuario::factory()->create(['activo' => false, 'vigente_hasta' => '2027-03-31']);  // desactivada

        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->getJson('/api/dashboard/resumen')->assertOk()->assertJsonPath('usuariosActivos', 3);
    }
}
