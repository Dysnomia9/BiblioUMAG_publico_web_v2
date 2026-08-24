<?php

namespace Tests\Feature;

use App\Models\EstadoLibroPersonalizado;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstadoLibroPersonalizadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_crear_un_estado_personalizado(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->postJson('/api/estados-libro-personalizados', ['nombre' => 'Restauración']);

        $response->assertStatus(201)->assertJsonPath('nombre', 'Restauración')->assertJsonPath('activo', true);
        $this->assertDatabaseHas('estados_libro_personalizados', ['nombre' => 'Restauración', 'activo' => true]);
    }

    public function test_staff_no_admin_no_puede_crear_un_estado_personalizado(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->postJson('/api/estados-libro-personalizados', ['nombre' => 'Restauración']);

        $response->assertStatus(403);
    }

    public function test_no_se_puede_crear_un_estado_personalizado_con_nombre_repetido(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        EstadoLibroPersonalizado::factory()->create(['nombre' => 'Restauración']);

        $response = $this->postJson('/api/estados-libro-personalizados', ['nombre' => 'Restauración']);

        $response->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_admin_puede_desactivar_un_estado_personalizado(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $estado = EstadoLibroPersonalizado::factory()->create(['activo' => true]);

        $response = $this->patchJson("/api/estados-libro-personalizados/{$estado->id}/activo", ['activo' => false]);

        $response->assertStatus(200)->assertJsonPath('activo', false);
    }

    public function test_index_filtra_por_activo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        // No se asume que parte de cero: la migración precarga estados activos por
        // defecto (Obsoleto, Pérdida, Hongos, Descarte, Dañado).
        $activosPrevios = EstadoLibroPersonalizado::where('activo', true)->count();
        EstadoLibroPersonalizado::factory()->create(['activo' => true]);
        EstadoLibroPersonalizado::factory()->create(['activo' => false]);

        $response = $this->getJson('/api/estados-libro-personalizados?activo=1');

        $response->assertStatus(200)->assertJsonCount($activosPrevios + 1);
    }
}
