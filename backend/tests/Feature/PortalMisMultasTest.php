<?php

namespace Tests\Feature;

use App\Models\Multa;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalMisMultasTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_ve_sus_propias_multas_pendientes(): void
    {
        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 5000]);

        Sanctum::actingAs($usuario);

        $response = $this->getJson('/api/mi/multas');

        $response->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 1)
            ->assertJsonPath('multas_pendientes.monto_total', 5000)
            ->assertJsonCount(1, 'multas');
    }

    public function test_multas_pagadas_o_anuladas_no_cuentan_como_deuda(): void
    {
        $usuario = Usuario::factory()->create();
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id]);
        Multa::factory()->anulada()->create(['usuario_id' => $usuario->id]);

        Sanctum::actingAs($usuario);

        $this->getJson('/api/mi/multas')
            ->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 0)
            ->assertJsonCount(0, 'multas');
    }

    public function test_usuario_sin_multas_pendientes_ve_cantidad_cero(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $response = $this->getJson('/api/mi/multas');

        $response->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 0)
            ->assertJsonPath('multas_pendientes.monto_total', 0);
    }

    public function test_no_devuelve_la_deuda_de_otro_usuario(): void
    {
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $otro->id, 'monto' => 9000]);

        Sanctum::actingAs($usuario);

        $response = $this->getJson('/api/mi/multas');

        $response->assertStatus(200)->assertJsonPath('multas_pendientes.cantidad', 0);
    }

    public function test_staff_no_puede_usar_la_ruta_del_portal(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->getJson('/api/mi/multas');

        $response->assertStatus(403);
    }

    public function test_mi_configuracion_es_accesible_desde_el_portal(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);

        $response = $this->getJson('/api/mi/configuracion');

        $response->assertStatus(200)->assertJsonStructure(['jefe_unidad_nombre', 'jefe_unidad_cargo']);
    }
}
