<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Las cuentas vencen el 31 de marzo y se renuevan a mano o viniendo en la carga masiva. */
class UsuarioVigenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_vencimiento_es_el_31_de_marzo_del_año_siguiente(): void
    {
        $this->assertSame('2027-03-31', Usuario::proximoVencimiento(Carbon::parse('2026-10-28'))->toDateString());
        $this->assertSame('2028-03-31', Usuario::proximoVencimiento(Carbon::parse('2027-02-10'))->toDateString());
        $this->assertSame('2028-03-31', Usuario::proximoVencimiento(Carbon::parse('2027-03-31'))->toDateString());
        $this->assertSame('2028-03-31', Usuario::proximoVencimiento(Carbon::parse('2027-04-01'))->toDateString());
        // Una carga hecha en diciembre dura hasta marzo; la de marzo del año siguiente, hasta marzo del subsiguiente.
        $this->assertSame('2027-03-31', Usuario::proximoVencimiento(Carbon::parse('2026-12-15'))->toDateString());
        $this->assertSame('2028-03-31', Usuario::proximoVencimiento(Carbon::parse('2027-03-15'))->toDateString());
    }

    public function test_una_cuenta_cuya_vigencia_paso_ya_no_esta_activa_pero_se_distingue_de_una_desactivada(): void
    {
        $vencida = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => now()->subDay()->toDateString()]);
        $desactivada = Usuario::factory()->create(['activo' => false, 'vigente_hasta' => now()->addYear()->toDateString()]);
        $vigente = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => now()->toDateString()]);
        $sinFecha = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => null]);

        $this->assertFalse($vencida->fresh()->activo);
        $this->assertSame('vencida', $vencida->fresh()->inactividad);
        $this->assertFalse($desactivada->fresh()->activo);
        $this->assertSame('desactivada', $desactivada->fresh()->inactividad);
        // El último día todavía sirve; sin fecha, no vence.
        $this->assertTrue($vigente->fresh()->activo);
        $this->assertTrue($sinFecha->fresh()->activo);
    }

    public function test_una_cuenta_vencida_no_se_lleva_material_ni_marca_entrada(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $usuario = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => '2026-03-31']);

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'libro', 'codigo_barras' => 'x', 'fecha_prestamo' => now()->toDateString(), 'fecha_devolucion' => now()->addDays(3)->toDateString()])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'venció el 31/03/2026'));

        $this->postJson('/api/entrada', ['rut' => $usuario->rut])
            ->assertStatus(403)
            ->assertJsonPath('inactividad', 'vencida');
    }

    public function test_renovar_corre_la_vigencia_y_queda_en_la_bitacora(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create(['nombre' => 'Valentina', 'apellido' => 'Soto', 'activo' => true, 'vigente_hasta' => '2026-03-31']);

        $this->patchJson("/api/usuarios/{$usuario->id}/habilitar")
            ->assertOk()
            ->assertJsonPath('activo', true)
            ->assertJsonPath('inactividad', null)
            ->assertJsonPath('vigente_hasta', fn ($v) => str_starts_with($v, '2027-03-31'));

        $registro = Bitacora::where('accion', 'usuarios.habilitar')->sole();
        $this->assertSame('Renovó la cuenta de Valentina Soto hasta el 31/03/2027', $registro->descripcion);
    }

    public function test_el_listado_filtra_activos_e_inactivos_segun_la_vigencia(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $vigente = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => now()->addMonth()->toDateString()]);
        $vencida = Usuario::factory()->create(['activo' => true, 'vigente_hasta' => now()->subMonth()->toDateString()]);
        $desactivada = Usuario::factory()->create(['activo' => false, 'vigente_hasta' => null]);

        $activos = collect($this->getJson('/api/usuarios?activo=true')->assertOk()->json('data'))->pluck('id');
        $inactivos = collect($this->getJson('/api/usuarios?activo=false')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($activos->contains($vigente->id));
        $this->assertFalse($activos->contains($vencida->id));
        $this->assertTrue($inactivos->contains($vencida->id));
        $this->assertTrue($inactivos->contains($desactivada->id));
        $this->assertFalse($inactivos->contains($vigente->id));
    }

    public function test_un_usuario_creado_a_mano_queda_vigente_hasta_el_proximo_31_de_marzo(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $id = $this->postJson('/api/usuarios', ['rut' => '12.345.678-5', 'nombre' => 'Ana', 'apellido' => 'Pérez', 'tipo' => 'estudiante'])
            ->assertCreated()
            ->json('id');

        $this->assertSame('2027-03-31', Usuario::find($id)->vigente_hasta->toDateString());
    }

    public function test_la_carga_masiva_renueva_a_los_que_vienen_y_deja_igual_a_los_que_no(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $vencida = Usuario::factory()->create(['rut' => '11.111.111-1', 'activo' => true, 'vigente_hasta' => '2026-03-31']);
        $ausente = Usuario::factory()->create(['rut' => '15.003.151-6', 'activo' => true, 'vigente_hasta' => '2026-03-31']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'nombre' => $vencida->nombre, 'apellido' => $vencida->apellido],
            ['fila' => 3, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona'],
        ]])->assertCreated();

        $this->assertSame('2027-03-31', $vencida->fresh()->vigente_hasta->toDateString());
        $this->assertTrue($vencida->fresh()->activo);
        // Quien no viene en el archivo no se toca: vence solo cuando le llegue su fecha.
        $this->assertSame('2026-03-31', $ausente->fresh()->vigente_hasta->toDateString());
        $this->assertFalse($ausente->fresh()->activo);
        $this->assertSame('2027-03-31', Usuario::where('rut', '12.345.678-5')->first()->vigente_hasta->toDateString());
    }

    public function test_la_planilla_puede_traer_la_fecha_de_vencimiento_y_esa_manda(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $existente = Usuario::factory()->create(['rut' => '11.111.111-1', 'activo' => true, 'vigente_hasta' => '2026-12-31']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'nombre' => $existente->nombre, 'apellido' => $existente->apellido, 'vigente_hasta' => '30/06/2027'],
            ['fila' => 3, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona', 'vigente_hasta' => '15-12-2026'],
        ]])->assertCreated();

        $this->assertSame('2027-06-30', $existente->fresh()->vigente_hasta->toDateString());
        $this->assertSame('2026-12-15', Usuario::where('rut', '12.345.678-5')->first()->vigente_hasta->toDateString());
    }

    public function test_una_fecha_ilegible_en_la_planilla_se_avisa_y_no_se_carga(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $respuesta = $this->postJson('/api/usuarios/importar/revisar', ['filas' => [
            ['fila' => 2, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona', 'vigente_hasta' => 'pronto'],
        ]])->assertOk();

        $respuesta->assertJsonPath('filas.0.estado', 'invalido');
        $this->assertStringContainsString('dd/mm/aaaa', $respuesta->json('filas.0.mensajes.0'));
    }

    public function test_el_personal_puede_cambiar_la_fecha_de_una_cuenta_a_mano(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $usuario = Usuario::factory()->create();

        $this->putJson("/api/usuarios/{$usuario->id}", [
            'tipo_documento' => 'rut', 'rut' => $usuario->rut, 'nombre' => $usuario->nombre, 'apellido' => $usuario->apellido,
            'tipo' => 'estudiante', 'vigente_hasta' => '2026-12-31',
        ])->assertOk();

        $this->assertSame('2026-12-31', $usuario->fresh()->vigente_hasta->toDateString());
    }
}
