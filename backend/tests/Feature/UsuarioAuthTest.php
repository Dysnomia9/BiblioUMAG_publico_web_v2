<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_con_correo_o_password_invalidos_devuelve_422(): void
    {
        $usuario = Usuario::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/auth/ingresar', [
            'identificador' => $usuario->email,
            'password' => 'password-incorrecta',
        ]);

        $response->assertStatus(422);
    }

    public function test_usuario_inactivo_con_credenciales_correctas_devuelve_422_por_cuenta_inactiva(): void
    {
        $usuario = Usuario::factory()->create([
            'password' => 'password123',
            'activo' => false,
        ]);

        $response = $this->postJson('/api/auth/ingresar', [
            'identificador' => $usuario->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.identificador.0', 'Tu cuenta se encuentra inactiva. Contacta a biblioteca.');
    }

    public function test_el_login_del_portal_por_rut_ya_no_existe(): void
    {
        $usuario = Usuario::factory()->create(['password' => 'password123']);

        $this->postJson('/api/auth/usuario/login', ['rut' => $usuario->rut, 'password' => 'password123'])
            ->assertNotFound();
    }
}
