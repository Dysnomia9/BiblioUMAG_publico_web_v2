<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultaBloqueoTest extends TestCase
{
    use RefreshDatabase;

    private function prestarLibro(Usuario $usuario, Ejemplar $ejemplar)
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $this->fechaConAtencion(7),
        ]);
    }

    public function test_con_una_multa_pendiente_no_se_presta_un_libro(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 3500]);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestarLibro($usuario, $ejemplar)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario tiene 1 multa pendiente por $3.500: debe pagarla antes de llevarse o reservar material.');

        $this->assertDatabaseMissing('prestamos', ['usuario_id' => $usuario->id]);
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => true]);
    }

    public function test_con_multas_pendientes_tampoco_se_presta_un_equipo(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();
        Multa::factory()->count(2)->create(['usuario_id' => $usuario->id, 'monto' => 600]);
        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'notebook',
            'codigo_barras' => $equipo->codigo_barras,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario tiene 2 multas pendientes por $1.200: debe pagarlas antes de llevarse o reservar material.');

        $this->assertDatabaseHas('equipos', ['id' => $equipo->id, 'disponible' => true]);
    }

    public function test_con_una_multa_pendiente_no_se_reserva_desde_el_meson_ni_desde_el_portal(): void
    {
        $usuario = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 720]);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ])->assertStatus(409);

        Sanctum::actingAs($usuario);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Tienes 1 multa pendiente por $720: debes pagarla en la biblioteca antes de reservar libros.');

        $this->assertDatabaseMissing('reservas_libro', ['usuario_id' => $usuario->id]);
    }

    public function test_una_multa_pagada_o_anulada_ya_no_bloquea(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id]);
        Multa::factory()->anulada()->create(['usuario_id' => $usuario->id]);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestarLibro($usuario, $ejemplar)->assertStatus(201);
    }

    public function test_al_pagar_la_multa_se_desbloquea_al_instante(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $multa = Multa::factory()->create(['usuario_id' => $usuario->id]);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestarLibro($usuario, $ejemplar)->assertStatus(409);
        $this->patchJson("/api/multas/{$multa->id}/pagar")->assertOk();
        $this->prestarLibro($usuario, $ejemplar)->assertStatus(201);
    }

    public function test_se_presta_el_libro_reservado_a_quien_lo_reservo_y_la_reserva_queda_retirada(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $reserva = ReservaLibro::factory()->create([
            'usuario_id' => $usuario->id,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
        ]);

        $this->prestarLibro($usuario, $ejemplar)->assertStatus(201);

        $this->assertDatabaseHas('reservas_libro', ['id' => $reserva->id, 'estado' => 'retirado']);
        $this->assertDatabaseHas('prestamos', ['usuario_id' => $usuario->id, 'ejemplar_id' => $ejemplar->id, 'estado' => 'activo']);
    }

    public function test_un_libro_reservado_para_otra_persona_no_se_presta(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        ReservaLibro::factory()->create(['libro_id' => $ejemplar->libro_id, 'ejemplar_id' => $ejemplar->id]);

        $this->prestarLibro(Usuario::factory()->create(), $ejemplar)->assertStatus(409);
    }
}
