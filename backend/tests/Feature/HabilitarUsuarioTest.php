<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Listado de Usuarios: habilitar a quien está inactivo (todo el personal) y desactivar (solo el Administrador/a). */
class HabilitarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migracion_deja_habilitar_a_los_roles_que_ven_el_listado_de_usuarios(): void
    {
        foreach (['asistente', 'bibliotecario'] as $clave) {
            $rol = Rol::where('clave', $clave)->first();
            $this->assertContains('usuarios.habilitar', $rol->permisosEfectivos(), $clave);
            $this->assertNotContains('usuarios.desactivar', $rol->permisosEfectivos(), $clave);
        }
    }

    public function test_atencion_de_publico_habilita_a_un_usuario_inactivo_y_queda_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create(['nombre' => 'Mesón Mañana']));
        $usuario = Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto', 'activo' => false]);

        $this->patchJson("/api/usuarios/{$usuario->id}/habilitar")
            ->assertOk()
            ->assertJsonPath('activo', true);

        $this->assertTrue($usuario->fresh()->activo);
        $registro = Bitacora::where('accion', 'usuarios.habilitar')->sole();
        $this->assertStringStartsWith('Renovó la cuenta de Valentina Soto hasta el ', $registro->descripcion);
        $this->assertSame('Mesón Mañana', $registro->actor_nombre);
        $this->assertSame([false, true], $registro->datos['activo']);
    }

    public function test_habilitar_a_quien_ya_esta_activo_no_cambia_nada_ni_repite_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['activo' => true]);

        $this->patchJson("/api/usuarios/{$usuario->id}/habilitar")->assertOk()->assertJsonPath('activo', true);

        $this->assertDatabaseMissing('bitacora', ['accion' => 'usuarios.habilitar']);
    }

    public function test_solo_el_administrador_desactiva_y_se_cierran_sus_sesiones_del_portal(): void
    {
        $usuario = Usuario::factory()->create(['nombre' => 'Matías', 'apellido' => 'Rojas', 'activo' => true]);
        $usuario->createToken('usuario-token');

        foreach ([Staff::factory()->asistente()->create(), Staff::factory()->create()] as $staff) {
            Sanctum::actingAs($staff);
            $this->patchJson("/api/usuarios/{$usuario->id}/desactivar")
                ->assertForbidden()
                ->assertJsonPath('message', 'Tu rol no tiene el permiso «Desactivar usuarios». Si lo necesitas, pídeselo a un administrador.');
        }
        $this->assertTrue($usuario->fresh()->activo);

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->patchJson("/api/usuarios/{$usuario->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('activo', false);

        $this->assertFalse($usuario->fresh()->activo);
        $this->assertSame(0, $usuario->tokens()->count());
        $this->assertSame([true, false], Bitacora::where('accion', 'usuarios.desactivar')->sole()->datos['activo']);
    }

    public function test_editar_la_ficha_tampoco_deja_desactivar_sin_ser_administrador(): void
    {
        // Un rol al que el administrador le dio la gestión de estudiantes, pero no es acceso total.
        Rol::create(['clave' => 'matricula', 'nombre' => 'Matrícula', 'nivel' => 4, 'permisos' => ['usuarios.gestionar']]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'matricula']));
        $activo = Usuario::factory()->create(['tipo_documento' => 'rut', 'activo' => true]);
        $inactivo = Usuario::factory()->create(['tipo_documento' => 'rut', 'activo' => false]);
        $ficha = fn (Usuario $u) => $u->only(['rut', 'tipo_documento', 'nombre', 'apellido', 'email', 'tipo', 'carrera', 'anio_ingreso', 'sexo']);

        $this->putJson("/api/usuarios/{$activo->id}", $ficha($activo) + ['activo' => false])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu rol no tiene el permiso «Desactivar usuarios». Si lo necesitas, pídeselo a un administrador.');
        $this->assertTrue($activo->fresh()->activo);

        // Sí puede guardar otros cambios, y habilitar a quien estaba inactivo.
        $this->putJson("/api/usuarios/{$activo->id}", ['carrera' => 'Enfermería', 'activo' => true] + $ficha($activo))->assertOk();
        $this->putJson("/api/usuarios/{$inactivo->id}", $ficha($inactivo) + ['activo' => true])->assertOk()->assertJsonPath('activo', true);
    }

    public function test_el_permiso_de_desactivar_no_se_le_puede_dar_a_otro_rol(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $rol = Rol::where('clave', 'bibliotecario')->first();

        $this->putJson("/api/roles/{$rol->id}", [
            'nombre' => $rol->nombre,
            'nivel' => 2,
            'permisos' => [...$rol->permisos, 'usuarios.desactivar'],
        ])->assertStatus(422);

        $this->assertNotContains('usuarios.desactivar', $rol->fresh()->permisosEfectivos());
    }
}
