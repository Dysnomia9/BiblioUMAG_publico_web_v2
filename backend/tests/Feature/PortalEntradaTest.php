<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalEntradaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_entrada_desde_el_portal_marca_fecha_hora_salida_de_inmediato(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $response = $this->postJson('/api/mi/entrada', [
            'rut' => $usuario->rut,
            'via' => 'manual',
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('entrada.fecha_hora_salida'));
    }

    public function test_la_entrada_con_rut_desde_el_portal_queda_como_portal_y_no_como_manual(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual'])->assertStatus(201);

        $this->assertDatabaseHas('entradas', ['usuario_id' => $usuario->id, 'via' => 'portal']);
        $this->assertDatabaseMissing('entradas', ['via' => 'manual']);
    }

    public function test_desde_el_portal_tampoco_se_marca_la_entrada_dos_veces_el_mismo_dia(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual'])->assertStatus(201);
        $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual'])
            ->assertStatus(409)
            ->assertJsonPath('ya_registrada', true);

        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_el_rut_del_portal_se_acepta_con_o_sin_puntos(): void
    {
        $usuario = Usuario::factory()->create(['rut' => '15.003.151-6']);
        Sanctum::actingAs($usuario);

        $this->postJson('/api/mi/entrada', ['rut' => '15003151-6', 'via' => 'manual'])->assertStatus(201);
    }

    public function test_estado_del_portal_no_arrastra_entradas_de_dias_anteriores(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        Entrada::factory()->create([
            'usuario_id' => $usuario->id,
            'fecha_hora_entrada' => now()->subDay(),
            'fecha_hora_salida' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/mi/estado');

        $response->assertStatus(200)
            ->assertJsonPath('personasEnSala', 0)
            ->assertJsonPath('horario.1', ['08:45', '20:30'])
            ->assertJsonPath('horario.6', ['09:00', '14:00'])
            ->assertJsonPath('horario.7', null);
    }
}
