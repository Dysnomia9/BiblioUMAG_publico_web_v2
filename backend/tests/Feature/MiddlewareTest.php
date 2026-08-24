<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_de_usuario_en_ruta_protegida_por_staff_devuelve_403(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());

        $response = $this->getJson('/api/usuarios');

        $response->assertStatus(403);
    }
}
