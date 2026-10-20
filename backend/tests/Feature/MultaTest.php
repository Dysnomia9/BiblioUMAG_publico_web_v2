<?php

namespace Tests\Feature;

use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultaTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_por_rut_incluye_conteo_y_monto_de_multas_pendientes(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 1500]);
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 900]);
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id, 'monto' => 700]);

        $this->getJson("/api/usuarios/rut/{$usuario->rut}")
            ->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 2)
            ->assertJsonPath('multas_pendientes.monto_total', 2400);
    }

    public function test_usuario_por_rut_sin_multas_pendientes_devuelve_cero(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();

        $this->getJson("/api/usuarios/rut/{$usuario->rut}")
            ->assertStatus(200)
            ->assertJsonPath('multas_pendientes.cantidad', 0)
            ->assertJsonPath('multas_pendientes.monto_total', 0);
    }

    public function test_resumen_agrupa_por_usuario_y_excluye_pagadas_y_anuladas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 1000]);
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 500]);
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id, 'monto' => 300]);
        Multa::factory()->anulada()->create(['monto' => 8000]);

        $this->getJson('/api/multas/resumen')
            ->assertStatus(200)
            ->assertJsonPath('total_usuarios', 1)
            ->assertJsonPath('total_multas', 2)
            ->assertJsonPath('monto_total', 1500)
            ->assertJsonPath('usuarios.0.usuario_id', $usuario->id)
            ->assertJsonPath('usuarios.0.email', $usuario->email)
            ->assertJsonPath('usuarios.0.cantidad_multas', 2)
            ->assertJsonPath('usuarios.0.monto_total', 1500);
    }

    public function test_listado_filtra_por_estado_y_usuario(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id]);
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id]);
        Multa::factory()->create();

        $this->getJson("/api/multas?usuario_id={$usuario->id}&estado=pendiente")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.usuario.id', $usuario->id)
            ->assertJsonPath('data.0.usuario.email', $usuario->email);
    }

    public function test_el_listado_de_multas_viene_por_paginas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Multa::factory()->count(30)->create();

        $this->getJson('/api/multas')->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 30)
            ->assertJsonPath('last_page', 2);
        $this->getJson('/api/multas?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('from', 26);
        $this->getJson('/api/multas?por_pagina=101')->assertStatus(422);
    }

    public function test_registrar_pago_deja_quien_y_cuando(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $multa = Multa::factory()->create();

        $this->patchJson("/api/multas/{$multa->id}/pagar")
            ->assertStatus(200)
            ->assertJsonPath('estado', 'pagada')
            ->assertJsonPath('pagada_por.id', $staff->id);

        $this->assertNotNull($multa->fresh()->pagada_en);
    }

    public function test_pagar_multa_que_no_esta_pendiente_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $multa = Multa::factory()->pagada()->create();

        $this->patchJson("/api/multas/{$multa->id}/pagar")->assertStatus(409);
    }

    public function test_anular_exige_motivo_y_lo_registra(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $multa = Multa::factory()->create();

        $this->patchJson("/api/multas/{$multa->id}/anular", [])->assertStatus(422);

        $this->patchJson("/api/multas/{$multa->id}/anular", ['motivo' => 'Se devolvió a tiempo, error del lector'])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'anulada')
            ->assertJsonPath('anulada_por.id', $staff->id)
            ->assertJsonPath('motivo_anulacion', 'Se devolvió a tiempo, error del lector');
    }

    public function test_no_se_puede_anular_una_multa_ya_pagada(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $multa = Multa::factory()->pagada()->create();

        $this->patchJson("/api/multas/{$multa->id}/anular", ['motivo' => 'Intento de anular'])->assertStatus(409);
    }

    public function test_cursar_multa_manual_por_perdida(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $usuario = Usuario::factory()->create();

        $this->postJson('/api/multas', [
            'usuario_id' => $usuario->id,
            'tipo' => 'perdida',
            'monto' => 25000,
            'detalle' => 'Pérdida del ejemplar de Cálculo I',
        ])->assertStatus(201)
            ->assertJsonPath('origen', 'manual')
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('registrada_por.id', $staff->id);
    }

    public function test_no_se_puede_cursar_a_mano_una_multa_de_atraso(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/multas', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo' => 'atraso',
            'monto' => 1000,
            'detalle' => 'Atraso inventado',
        ])->assertStatus(422)->assertJsonValidationErrors('tipo');
    }

    public function test_multa_manual_no_puede_asociarse_a_un_prestamo_de_otro_usuario(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamoAjeno = Prestamo::factory()->create();

        $this->postJson('/api/multas', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo' => 'deterioro',
            'monto' => 3000,
            'detalle' => 'Tapa dañada',
            'prestamo_id' => $prestamoAjeno->id,
        ])->assertStatus(422);
    }

    public function test_asistente_de_meson_ve_multas_pero_no_puede_cobrar_anular_ni_cursar(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $multa = Multa::factory()->create();

        $this->getJson('/api/multas')->assertStatus(200);
        $this->getJson('/api/multas/resumen')->assertStatus(200);
        $this->patchJson("/api/multas/{$multa->id}/pagar")->assertStatus(403);
        $this->patchJson("/api/multas/{$multa->id}/anular", ['motivo' => 'Quiero borrarla'])->assertStatus(403);
        $this->postJson('/api/multas', [
            'usuario_id' => $multa->usuario_id,
            'tipo' => 'otro',
            'monto' => 1000,
            'detalle' => 'Prueba de permiso',
        ])->assertStatus(403);

        $this->assertSame('pendiente', $multa->fresh()->estado);
    }
}
