<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Rol;
use App\Models\Staff;
use App\Support\Bitacora as Registro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Personal y Roles → Eliminar, para quien dejó la biblioteca: la cuenta desaparece y
 * no vuelve a entrar, pero su historial (bitácora, préstamos, multas…) se conserva
 * y la sigue nombrando.
 */
class PersonalEliminarTest extends TestCase
{
    use RefreshDatabase;

    private function comoAdmin(): Staff
    {
        $admin = Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function eliminar(Staff $staff, ?string $confirmacion = 'confirmar')
    {
        return $this->deleteJson("/api/personal/{$staff->id}", $confirmacion === null ? [] : ['confirmacion' => $confirmacion]);
    }

    public function test_solo_se_elimina_una_cuenta_desactivada_escribiendo_confirmar_y_nunca_la_propia(): void
    {
        $admin = $this->comoAdmin();
        $activa = Staff::factory()->create();
        $desactivada = Staff::factory()->create(['activo' => false]);

        $this->eliminar($activa)->assertStatus(409)->assertJsonPath('message', 'Desactiva la cuenta antes de eliminarla.');
        $this->eliminar($desactivada, null)->assertStatus(422)->assertJsonPath('errors.confirmacion.0', 'Escribe «confirmar» para eliminar la cuenta.');
        $this->eliminar($desactivada, 'sí')->assertStatus(422)->assertJsonValidationErrors('confirmacion');
        $this->eliminar($admin)->assertStatus(409);
        $this->assertNotSoftDeleted('staff', ['id' => $desactivada->id]);

        $this->eliminar($desactivada, ' Confirmar ')->assertNoContent();
        $this->assertSoftDeleted('staff', ['id' => $desactivada->id]);

        // Sin el permiso de cuentas y roles, no se elimina a nadie.
        Sanctum::actingAs(Staff::factory()->create());
        $this->eliminar(Staff::factory()->create(['activo' => false]))->assertForbidden();
    }

    public function test_su_historial_se_conserva_y_la_sigue_nombrando(): void
    {
        $this->comoAdmin();
        $rosa = Staff::factory()->create(['nombre' => 'Rosa Barría', 'email' => 'rosa@umag.cl', 'activo' => false]);
        $prestamo = Prestamo::factory()->create(['prestado_por_staff_id' => $rosa->id, 'prestado_por' => 'Rosa Barría']);
        $multa = Multa::factory()->create(['registrada_por_staff_id' => $rosa->id]);
        $accion = Registro::registrar('prestamos.prestar', 'Prestó «Cálculo I»', actor: $rosa);

        $this->eliminar($rosa)->assertNoContent();

        $this->assertDatabaseHas('bitacora', ['id' => $accion->id, 'staff_id' => $rosa->id, 'actor_nombre' => 'Rosa Barría']);
        $this->assertSame('Rosa Barría', $prestamo->fresh()->prestadoPorStaff->nombre);
        $this->assertSame('Rosa Barría', $multa->fresh()->registradaPor->nombre);

        // Ya no está en Personal y Roles, pero sí en el filtro de la bitácora, marcada.
        $this->assertNotContains($rosa->id, collect($this->getJson('/api/personal')->assertOk()->json())->pluck('id'));
        $this->getJson('/api/bitacora/opciones')->assertOk()->assertJsonFragment(['id' => $rosa->id, 'nombre' => 'Rosa Barría', 'eliminada' => true]);

        $registro = Bitacora::where('accion', 'personal.eliminar')->sole();
        $this->assertSame('rosa@umag.cl', $registro->datos['email']);
        $this->assertStringContainsString('Rosa Barría', $registro->descripcion);
    }

    public function test_no_vuelve_a_entrar_y_su_correo_queda_libre_para_una_cuenta_nueva(): void
    {
        $this->comoAdmin();
        $rosa = Staff::factory()->create(['nombre' => 'Rosa Barría', 'email' => 'rosa@umag.cl', 'password' => 'secreta123', 'activo' => false]);
        $rosa->createToken('sesion-vieja');

        $this->eliminar($rosa)->assertNoContent();

        $this->assertSame(0, $rosa->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => 'rosa@umag.cl', 'password' => 'secreta123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales no coinciden con ningún registro.');

        $this->postJson('/api/personal', ['nombre' => 'Rosa Barría', 'email' => 'rosa@umag.cl', 'password' => 'otraClave123', 'rol' => 'asistente'])
            ->assertCreated();
        $this->assertSame(2, Staff::withTrashed()->where('nombre', 'Rosa Barría')->count());
    }

    public function test_un_rol_que_solo_tenian_cuentas_eliminadas_se_puede_borrar(): void
    {
        $this->comoAdmin();
        $rol = Rol::create(['clave' => 'temporal', 'nombre' => 'Temporal', 'nivel' => 5, 'permisos' => []]);
        $rosa = Staff::factory()->create(['rol' => 'temporal', 'activo' => false]);
        $this->eliminar($rosa)->assertNoContent();

        $this->deleteJson("/api/roles/{$rol->id}")->assertNoContent();

        $this->assertNotSame('temporal', Staff::withTrashed()->find($rosa->id)->rol);
        $this->assertSoftDeleted('staff', ['id' => $rosa->id]);
    }
}
