<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultasPendientesTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_por_rut_incluye_conteo_y_monto_de_multas_pendientes(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();
        Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'estado' => 'devuelto',
            'multa_estado' => 'pendiente',
            'multa_monto' => 1500,
        ]);
        Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'estado' => 'devuelto',
            'multa_estado' => 'pendiente',
            'multa_monto' => 900,
        ]);

        $response = $this->getJson("/api/usuarios/rut/{$usuario->rut}");

        $response->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 2)
            ->assertJsonPath('multas_pendientes.monto_total', 2400);
    }

    public function test_usuario_por_rut_sin_multas_pendientes_devuelve_cero(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();

        $response = $this->getJson("/api/usuarios/rut/{$usuario->rut}");

        $response->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 0)
            ->assertJsonPath('multas_pendientes.monto_total', 0);
    }

    public function test_reporte_multas_pendientes_agrupa_por_usuario_y_suma_montos_de_varios_prestamos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();
        Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'multa_estado' => 'pendiente',
            'multa_monto' => 1000,
        ]);
        Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'multa_estado' => 'pendiente',
            'multa_monto' => 500,
        ]);

        $response = $this->getJson('/api/reportes/multas-pendientes');

        $response->assertStatus(200)
            ->assertJsonPath('total_usuarios', 1)
            ->assertJsonPath('monto_total', 1500)
            ->assertJsonPath('usuarios.0.usuario_id', $usuario->id)
            ->assertJsonPath('usuarios.0.cantidad_prestamos', 2)
            ->assertJsonPath('usuarios.0.monto_total', 1500);
    }

    public function test_reporte_multas_pendientes_excluye_multas_pagadas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'multa_estado' => 'pagada',
            'multa_monto' => 1000,
        ]);

        $response = $this->getJson('/api/reportes/multas-pendientes');

        $response->assertStatus(200)
            ->assertJsonPath('total_usuarios', 0)
            ->assertJsonPath('monto_total', 0)
            ->assertJsonCount(0, 'usuarios');
    }
}
