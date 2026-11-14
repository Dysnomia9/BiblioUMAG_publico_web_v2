<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aspecto visual: cada persona del personal elige el fondo del panel en modo día y queda en
 * su cuenta (la sesión lo trae en /auth/me). Los usuarios del portal, igual, en /auth/usuario/fondo.
 */
class FondoPersonalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_elegir_la_sesion_trae_el_fondo_vacio(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('fondo', null);
    }

    public function test_el_fondo_elegido_queda_en_la_cuenta_sin_editarla(): void
    {
        $staff = Staff::factory()->create();
        $editada = $staff->updated_at;
        Sanctum::actingAs($staff);

        $this->travel(5)->minutes();
        $this->patchJson('/api/auth/fondo', ['fondo' => 'patagonia'])->assertOk()->assertExactJson(['fondo' => 'patagonia']);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('fondo', 'patagonia');
        // Elegir un fondo no es editar la cuenta.
        $this->assertEquals($editada, $staff->fresh()->updated_at);
    }

    public function test_solo_se_aceptan_los_fondos_de_la_lista(): void
    {
        $staff = Staff::factory()->create(['fondo' => 'lino']);
        Sanctum::actingAs($staff);

        $this->patchJson('/api/auth/fondo', ['fondo' => 'fucsia-neon'])->assertStatus(422)->assertJsonValidationErrors('fondo');
        $this->patchJson('/api/auth/fondo', [])->assertStatus(422)->assertJsonValidationErrors('fondo');
        $this->assertSame('lino', $staff->fresh()->fondo);
    }

    public function test_cada_persona_tiene_su_fondo(): void
    {
        $ana = Staff::factory()->create();
        $beto = Staff::factory()->create(['fondo' => 'marfil']);
        Sanctum::actingAs($ana);

        $this->patchJson('/api/auth/fondo', ['fondo' => 'aurora'])->assertOk();

        $this->assertSame('aurora', $ana->fresh()->fondo);
        $this->assertSame('marfil', $beto->fresh()->fondo);
    }

    public function test_un_usuario_del_portal_no_usa_la_ruta_del_personal(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());

        $this->patchJson('/api/auth/fondo', ['fondo' => 'papel'])->assertForbidden();
    }

    public function test_el_usuario_del_portal_elige_su_fondo(): void
    {
        $usuario = Usuario::factory()->create();
        $editada = $usuario->updated_at;
        Sanctum::actingAs($usuario);

        $this->getJson('/api/auth/usuario/me')->assertOk()->assertJsonPath('fondo', null);
        $this->travel(5)->minutes();
        $this->patchJson('/api/auth/usuario/fondo', ['fondo' => 'rosado'])->assertOk()->assertExactJson(['fondo' => 'rosado']);

        $this->getJson('/api/auth/usuario/me')->assertOk()->assertJsonPath('fondo', 'rosado');
        $this->assertEquals($editada, $usuario->fresh()->updated_at);
    }

    public function test_el_usuario_del_portal_solo_elige_fondos_de_la_lista(): void
    {
        $usuario = Usuario::factory()->create(['fondo' => 'lino']);
        Sanctum::actingAs($usuario);

        $this->patchJson('/api/auth/usuario/fondo', ['fondo' => 'fucsia-neon'])->assertStatus(422)->assertJsonValidationErrors('fondo');
        $this->assertSame('lino', $usuario->fresh()->fondo);
    }

    public function test_el_personal_no_usa_la_ruta_del_portal(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->patchJson('/api/auth/usuario/fondo', ['fondo' => 'papel'])->assertForbidden();
    }

    public function test_sin_sesion_no_se_puede_cambiar(): void
    {
        $this->patchJson('/api/auth/fondo', ['fondo' => 'papel'])->assertUnauthorized();
    }
}
