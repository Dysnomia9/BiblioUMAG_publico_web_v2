<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_de_staff_con_credenciales_validas_devuelve_token(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token', 'staff']);
    }

    public function test_login_de_staff_con_password_incorrecta_devuelve_422(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'incorrecta',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_de_staff_inactivo_con_credenciales_correctas_devuelve_422_por_cuenta_inactiva(): void
    {
        $staff = Staff::factory()->create(['password' => 'password123', 'activo' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }
}
