<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalYRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_crea_una_cuenta_con_rol_y_puede_iniciar_sesion(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/personal', [
            'nombre' => 'Pedro Soto',
            'email' => 'pedro@umag.cl',
            'password' => 'meson1234',
            'rol' => 'asistente',
        ])->assertStatus(201)
            ->assertJsonPath('rol', 'asistente')
            ->assertJsonPath('rol_nombre', 'Atención de público')
            ->assertJsonMissingPath('password');

        $this->postJson('/api/auth/login', ['email' => 'pedro@umag.cl', 'password' => 'meson1234'])
            ->assertStatus(200)
            ->assertJsonPath('staff.rol', 'asistente');
    }

    public function test_crear_cuenta_con_email_repetido_falla(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create(['email' => 'ya@umag.cl']));

        $this->postJson('/api/personal', [
            'nombre' => 'Otra',
            'email' => 'ya@umag.cl',
            'password' => 'clave12345',
            'rol' => 'asistente',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_el_mismo_correo_con_mayusculas_tambien_esta_repetido(): void
    {
        $admin = Staff::factory()->admin()->create(['email' => 'ya@umag.cl']);
        $otra = Staff::factory()->create(['email' => 'otra@umag.cl']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/personal', [
            'nombre' => 'Otra',
            'email' => 'YA@umag.cl',
            'password' => 'clave12345',
            'rol' => 'asistente',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->putJson("/api/personal/{$otra->id}", ['nombre' => $otra->nombre, 'email' => 'Ya@UMAG.cl', 'rol' => $otra->rol])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->putJson("/api/personal/{$otra->id}", ['nombre' => $otra->nombre, 'email' => 'Otra.Nueva@umag.cl', 'rol' => $otra->rol])->assertOk();
        $this->assertSame('otra.nueva@umag.cl', $otra->fresh()->email);
    }

    public function test_carga_varias_cuentas_y_cada_una_entra_con_su_rol_y_su_contrasena(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Ana Rojas', 'email' => 'Ana.Rojas@umag.cl', 'password' => 'clave-de-ana', 'rol' => 'admin'],
            ['nombre' => 'Luis Vera', 'email' => 'luis.vera@umag.cl', 'password' => 'clave-de-luis', 'rol' => 'asistente'],
        ]])->assertStatus(201)
            ->assertJsonCount(2)
            ->assertJsonPath('0.email', 'ana.rojas@umag.cl')
            ->assertJsonPath('0.rol_nombre', 'Administrador/a')
            ->assertJsonPath('1.rol_nombre', 'Atención de público')
            ->assertJsonMissingPath('0.password');

        $this->postJson('/api/auth/login', ['email' => 'ana.rojas@umag.cl', 'password' => 'clave-de-ana'])
            ->assertStatus(200)->assertJsonPath('staff.rol', 'admin');
        $this->postJson('/api/auth/login', ['email' => 'luis.vera@umag.cl', 'password' => 'clave-de-luis'])
            ->assertStatus(200)->assertJsonPath('staff.rol', 'asistente');
    }

    public function test_la_carga_de_cuentas_queda_en_la_bitacora_sin_las_contrasenas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Ana Rojas', 'email' => 'ana.rojas@umag.cl', 'password' => 'clave-de-ana', 'rol' => 'admin'],
            ['nombre' => 'Luis Vera', 'email' => 'luis.vera@umag.cl', 'password' => 'clave-de-luis', 'rol' => 'asistente'],
        ]])->assertStatus(201);

        $registros = Bitacora::where('accion', 'personal.crear')->get();
        $this->assertCount(2, $registros);
        $this->assertStringNotContainsString('clave-de', $registros->toJson());
    }

    public function test_si_un_correo_ya_tiene_cuenta_la_carga_no_crea_ninguna(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $existente = Staff::factory()->asistente()->create(['email' => 'ya@umag.cl']);
        $total = Staff::count();

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Nueva', 'email' => 'nueva@umag.cl', 'password' => 'clave12345', 'rol' => 'asistente'],
            ['nombre' => 'Otro rol', 'email' => 'YA@umag.cl', 'password' => 'clave12345', 'rol' => 'admin'],
        ]])->assertStatus(422)
            ->assertJsonValidationErrors('cuentas.1.email')
            ->assertJsonPath('message', 'Ya existe una cuenta del personal con el correo ya@umag.cl.');

        $this->assertSame($total, Staff::count());
        $this->assertSame('asistente', $existente->fresh()->rol);
        $this->assertSame(0, Bitacora::where('accion', 'personal.crear')->count());
    }

    public function test_la_carga_rechaza_correos_repetidos_aunque_cambien_las_mayusculas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $total = Staff::count();

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Ana', 'email' => 'ana@umag.cl', 'password' => 'clave12345', 'rol' => 'asistente'],
            ['nombre' => 'Ana otra vez', 'email' => 'ANA@umag.cl', 'password' => 'clave12345', 'rol' => 'asistente'],
        ]])->assertStatus(422)->assertJsonValidationErrors('cuentas.1.email');

        $this->assertSame($total, Staff::count());
    }

    public function test_la_carga_de_cuentas_valida_cada_fila_y_el_tamano_de_la_lista(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $total = Staff::count();

        $this->postJson('/api/personal/lote', ['cuentas' => []])->assertStatus(422)->assertJsonValidationErrors('cuentas');
        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => '', 'email' => 'no-es-correo', 'password' => 'corta', 'rol' => 'no-existe'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['cuentas.0.nombre', 'cuentas.0.email', 'cuentas.0.password', 'cuentas.0.rol']);

        $demasiadas = array_map(
            fn (int $i) => ['nombre' => "Persona {$i}", 'email' => "persona{$i}@umag.cl", 'password' => 'clave12345', 'rol' => 'asistente'],
            range(1, 51),
        );
        $this->postJson('/api/personal/lote', ['cuentas' => $demasiadas])->assertStatus(422)->assertJsonValidationErrors('cuentas');

        $this->assertSame($total, Staff::count());
    }

    public function test_solo_quien_gestiona_el_personal_puede_cargar_cuentas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Intruso', 'email' => 'intruso@umag.cl', 'password' => 'clave12345', 'rol' => 'admin'],
        ]])->assertStatus(403);

        $this->assertNull(Staff::where('email', 'intruso@umag.cl')->first());
    }

    public function test_quien_ya_es_usuario_entra_al_panel_con_la_contrasena_de_la_cuenta_cargada(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        Usuario::factory()->create(['email' => 'marta@umag.cl', 'password' => 'clave-del-portal']);

        $this->postJson('/api/personal/lote', ['cuentas' => [
            ['nombre' => 'Marta', 'email' => 'marta@umag.cl', 'password' => 'clave-del-panel', 'rol' => 'asistente'],
        ]])->assertStatus(201);

        $this->postJson('/api/auth/ingresar', ['identificador' => 'marta@umag.cl', 'password' => 'clave-del-panel'])
            ->assertStatus(200)->assertJsonPath('tipo', 'staff');
        $this->postJson('/api/auth/ingresar', ['identificador' => 'marta@umag.cl', 'password' => 'clave-del-portal'])
            ->assertStatus(200)->assertJsonPath('tipo', 'usuario');
    }

    public function test_cambiar_rol_de_una_cuenta(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $staff = Staff::factory()->asistente()->create();

        $this->putJson("/api/personal/{$staff->id}", [
            'nombre' => $staff->nombre,
            'email' => $staff->email,
            'rol' => 'bibliotecario',
        ])->assertStatus(200)->assertJsonPath('rol', 'bibliotecario');
    }

    public function test_no_se_puede_quitar_el_rol_a_la_unica_cuenta_con_acceso_total(): void
    {
        $admin = Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/personal/{$admin->id}", [
            'nombre' => $admin->nombre,
            'email' => $admin->email,
            'rol' => 'asistente',
        ])->assertStatus(409);

        $this->assertSame('admin', $admin->fresh()->rol);
    }

    public function test_con_dos_administradores_uno_puede_dejar_de_serlo(): void
    {
        $admin = Staff::factory()->admin()->create();
        $otro = Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/personal/{$otro->id}", [
            'nombre' => $otro->nombre,
            'email' => $otro->email,
            'rol' => 'bibliotecario',
        ])->assertStatus(200);
    }

    public function test_desactivar_cuenta_revoca_sus_sesiones(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $staff = Staff::factory()->asistente()->create();
        $staff->createToken('staff-token');

        $this->patchJson("/api/personal/{$staff->id}/activo", ['activo' => false])
            ->assertStatus(200)
            ->assertJsonPath('activo', false);

        $this->assertSame(0, $staff->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertStatus(422);
    }

    public function test_no_puede_desactivarse_a_si_mismo(): void
    {
        $admin = Staff::factory()->admin()->create();
        Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/personal/{$admin->id}/activo", ['activo' => false])->assertStatus(409);
    }

    public function test_restablecer_contrasena_de_otra_cuenta(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $staff = Staff::factory()->asistente()->create();

        $this->patchJson("/api/personal/{$staff->id}/password", ['password' => 'corta'])->assertStatus(422);
        $this->patchJson("/api/personal/{$staff->id}/password", ['password' => 'nueva-clave-1'])->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'nueva-clave-1'])->assertStatus(200);
    }

    public function test_listado_de_roles_trae_catalogo_y_conteo_de_cuentas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        Staff::factory()->asistente()->count(2)->create();

        $response = $this->getJson('/api/roles')->assertStatus(200);

        $asistente = collect($response->json('roles'))->firstWhere('clave', 'asistente');
        $this->assertSame(2, $asistente['staff_count']);
        $this->assertNotEmpty($response->json('catalogo'));
    }

    public function test_crear_rol_personalizado_y_asignarlo(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $response = $this->postJson('/api/roles', [
            'nombre' => 'Ayudante de sala',
            'descripcion' => 'Solo registra entradas',
            'nivel' => 4,
            'permisos' => ['entradas.registrar'],
        ])->assertStatus(201)
            ->assertJsonPath('clave', 'ayudante_de_sala')
            ->assertJsonPath('permisos', ['entradas.registrar']);

        $ayudante = Staff::factory()->create(['rol' => $response->json('clave')]);
        Sanctum::actingAs($ayudante);

        $this->getJson('/api/entrada')->assertStatus(200);
        $this->getJson('/api/prestamos')->assertStatus(403);
    }

    public function test_ningun_rol_editable_puede_recibir_la_gestion_de_personal(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $rol = Rol::where('clave', 'bibliotecario')->first();

        $this->putJson("/api/roles/{$rol->id}", [
            'nombre' => $rol->nombre,
            'nivel' => 2,
            'permisos' => ['personal.gestionar'],
        ])->assertStatus(422);
    }

    public function test_los_permisos_del_rol_con_acceso_total_no_se_editan(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $admin = Rol::where('clave', 'admin')->first();

        $this->putJson("/api/roles/{$admin->id}", [
            'nombre' => 'Jefatura de biblioteca',
            'permisos' => ['entradas.registrar'],
        ])->assertStatus(200)->assertJsonPath('nombre', 'Jefatura de biblioteca');

        $this->assertTrue($admin->fresh()->acceso_total);
        $this->assertContains('personal.gestionar', $admin->fresh()->permisosEfectivos());
    }

    public function test_no_se_elimina_un_rol_con_cuentas_asignadas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        Staff::factory()->asistente()->create();
        $asistente = Rol::where('clave', 'asistente')->first();

        $this->deleteJson("/api/roles/{$asistente->id}")->assertStatus(409);

        $vacio = Rol::create(['clave' => 'temporal', 'nombre' => 'Temporal', 'nivel' => 5, 'permisos' => []]);
        $this->deleteJson("/api/roles/{$vacio->id}")->assertStatus(204);
    }
}
