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

    public function test_registrar_entrada_dos_veces_el_mismo_dia_desde_el_portal_ya_no_se_bloquea(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual'])->assertStatus(201);
        $this->postJson('/api/mi/entrada', ['rut' => $usuario->rut, 'via' => 'manual'])->assertStatus(201);

        $this->assertDatabaseCount('entradas', 2);
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
            ->assertJsonPath('personasEnSala', 0);
    }
}
