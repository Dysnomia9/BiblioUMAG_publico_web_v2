<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\TipoMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TipoMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_todo_staff_puede_listar_los_tipos_de_material(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->getJson('/api/tipos-material');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(5, count($response->json()));
    }

    public function test_admin_puede_crear_un_tipo_de_material(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->postJson('/api/tipos-material', ['nombre' => 'Mapa']);

        $response->assertStatus(201)->assertJsonPath('nombre', 'Mapa');
        $this->assertDatabaseHas('tipos_material', ['nombre' => 'Mapa']);
    }

    public function test_staff_no_admin_no_puede_crear_un_tipo_de_material(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->postJson('/api/tipos-material', ['nombre' => 'Mapa']);

        $response->assertStatus(403);
    }

    public function test_no_se_puede_crear_un_tipo_de_material_con_nombre_repetido(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        TipoMaterial::factory()->create(['nombre' => 'Mapa']);

        $response = $this->postJson('/api/tipos-material', ['nombre' => 'Mapa']);

        $response->assertStatus(422)->assertJsonValidationErrors('nombre');
    }
}
