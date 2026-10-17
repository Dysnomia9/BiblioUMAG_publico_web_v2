<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalIngresosTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_usuario_ve_solo_su_historial_de_ingresos_con_su_origen(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0));
        $usuario = Usuario::factory()->create();
        $staff = Staff::factory()->create();

        Entrada::factory()->create(['usuario_id' => $usuario->id, 'via' => 'qr', 'fecha_hora_entrada' => Carbon::create(2026, 9, 15, 9, 5)]);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'via' => 'manual', 'registrado_por_staff_id' => $staff->id, 'fecha_hora_entrada' => Carbon::create(2026, 9, 14, 17, 40)]);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'via' => 'portal', 'fecha_hora_entrada' => Carbon::create(2026, 8, 28, 11, 0)]);
        Entrada::factory()->create(['usuario_id' => Usuario::factory()->create()->id, 'fecha_hora_entrada' => Carbon::create(2026, 9, 15, 10, 0)]);

        Sanctum::actingAs($usuario);

        $this->getJson('/api/mi/ingresos')
            ->assertOk()
            ->assertJsonPath('este_mes', 2)
            ->assertJsonCount(3, 'entradas')
            ->assertJsonPath('entradas.0.fecha', '2026-09-15')
            ->assertJsonPath('entradas.0.hora', '09:05')
            ->assertJsonPath('entradas.0.origen', 'qr')
            ->assertJsonPath('entradas.1.origen', 'meson')
            ->assertJsonPath('entradas.2.origen', 'rut');
    }
}
