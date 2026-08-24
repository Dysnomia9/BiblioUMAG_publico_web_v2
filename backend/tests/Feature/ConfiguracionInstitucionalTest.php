<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConfiguracionInstitucionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_todo_staff_puede_ver_la_configuracion(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->getJson('/api/configuracion');

        $response->assertStatus(200)->assertJsonStructure(['jefe_unidad_nombre', 'jefe_unidad_cargo']);
    }

    public function test_admin_puede_actualizar_la_configuracion(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->putJson('/api/configuracion', [
            'jefe_unidad_nombre' => 'Nueva Jefa',
            'jefe_unidad_cargo' => 'Nuevo Cargo',
        ]);

        $response->assertStatus(200)->assertJsonPath('jefe_unidad_nombre', 'Nueva Jefa');
        $this->assertDatabaseHas('configuracion_institucional', ['jefe_unidad_nombre' => 'Nueva Jefa']);
    }

    public function test_staff_no_admin_no_puede_actualizar_la_configuracion(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->putJson('/api/configuracion', [
            'jefe_unidad_nombre' => 'Nueva Jefa',
            'jefe_unidad_cargo' => 'Nuevo Cargo',
        ]);

        $response->assertStatus(403);
    }
}
