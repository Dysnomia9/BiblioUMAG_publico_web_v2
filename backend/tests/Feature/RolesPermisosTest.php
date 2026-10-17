<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Rol;
use App\Models\Staff;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolesPermisosTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migracion_crea_los_tres_roles_por_defecto(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'bibliotecario', 'asistente'], Rol::pluck('clave')->all());
        $this->assertTrue(Rol::where('clave', 'admin')->value('acceso_total'));
    }

    public function test_me_devuelve_los_permisos_efectivos_del_rol(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('rol', 'asistente')
            ->assertJsonPath('rol_nombre', 'Atención de público')
            ->assertJsonPath('rol_acceso_total', false)
            ->assertJsonFragment(['permisos' => Rol::where('clave', 'asistente')->first()->permisosEfectivos()]);
    }

    public function test_admin_tiene_todo_el_catalogo_de_permisos(): void
    {
        $admin = Staff::factory()->admin()->create();

        $this->assertEqualsCanonicalizing(Permisos::claves(), $admin->permisos());
        $this->assertTrue($admin->puede('personal.gestionar'));
    }

    public function test_asistente_de_meson_atiende_publico_pero_no_administra_coleccion_ni_sistema(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        // Lo que sí puede: entradas, préstamos, salas.
        $this->getJson('/api/entrada')->assertStatus(200);
        $this->getJson('/api/prestamos')->assertStatus(200);
        $this->getJson('/api/salas')->assertStatus(200);
        $this->getJson('/api/codigo-acceso')->assertStatus(200);

        // Lo que no: catalogar, estado de libro, cambio masivo, historiales, QR,
        // equipos, reportes y todo lo de administración.
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $this->postJson('/api/libros', ['titulo' => 'X'])->assertStatus(403);
        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja'])->assertStatus(403);
        $this->postJson('/api/ejemplares/cambio-masivo/preview', [])->assertStatus(403);
        $this->getJson('/api/ejemplares/historial-estado')->assertStatus(403);
        $this->getJson('/api/libros/historial')->assertStatus(403);
        $this->postJson('/api/codigo-acceso/regenerar')->assertStatus(403);
        $this->postJson('/api/equipos', [])->assertStatus(403);
        $this->getJson('/api/reportes/resumen')->assertStatus(403);
        $this->putJson('/api/configuracion', [])->assertStatus(403);
        $this->postJson('/api/ubicaciones', ['nombre' => 'Sede Nueva'])->assertStatus(403);
        $this->postJson('/api/tipos-material', ['nombre' => 'Mapa'])->assertStatus(403);
        $this->getJson('/api/personal')->assertStatus(403);
        $this->getJson('/api/registro-absoluto')->assertStatus(403);

        $this->assertSame('en_estante', $ejemplar->fresh()->estado_proceso);
    }

    public function test_respuesta_403_nombra_el_permiso_que_falta(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->postJson('/api/libros', ['titulo' => 'X'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu rol no tiene el permiso «Catalogar libros». Si lo necesitas, pídeselo a un administrador.');
    }

    public function test_bibliotecario_cataloga_pero_no_administra_el_sistema(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'bibliotecario']));

        $this->getJson('/api/ejemplares/siguiente-codigo-barras')->assertStatus(200);
        $this->getJson('/api/libros/historial')->assertStatus(200);
        $this->putJson('/api/configuracion', ['jefe_unidad_nombre' => 'X', 'jefe_unidad_cargo' => 'Y'])->assertStatus(403);
        $this->getJson('/api/personal')->assertStatus(403);
        $this->getJson('/api/roles')->assertStatus(403);
    }

    public function test_quitar_un_permiso_al_rol_se_aplica_de_inmediato(): void
    {
        $asistente = Staff::factory()->asistente()->create();
        Sanctum::actingAs($asistente);
        $this->getJson('/api/salas')->assertStatus(200);

        $rol = Rol::where('clave', 'asistente')->first();
        $rol->update(['permisos' => array_values(array_diff($rol->permisos, ['salas.gestionar']))]);

        // Nueva request, nuevo modelo: no debe quedar cacheado el rol anterior.
        Sanctum::actingAs($asistente->fresh());
        $this->getJson('/api/salas')->assertStatus(403);
    }

    public function test_cuenta_desactivada_no_puede_seguir_usando_su_token(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['activo' => false]));

        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_staff_puede_cambiar_su_propia_contrasena(): void
    {
        $staff = Staff::factory()->asistente()->create(['password' => 'clave-antigua']);
        Sanctum::actingAs($staff);

        $this->patchJson('/api/auth/password', [
            'actual' => 'incorrecta',
            'nueva' => 'clave-nueva-1',
            'nueva_confirmation' => 'clave-nueva-1',
        ])->assertStatus(422)->assertJsonValidationErrors('actual');

        $this->patchJson('/api/auth/password', [
            'actual' => 'clave-antigua',
            'nueva' => 'clave-nueva-1',
            'nueva_confirmation' => 'clave-nueva-1',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'clave-nueva-1'])->assertStatus(200);
    }
}
