<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\PoliticaVigencia;
use App\Models\Rol;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cuánto dura la cuenta de cada tipo al renovarla, y la fecha que se puede elegir en cada carga masiva. */
class PoliticaVigenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function politicas(array $cambios = []): array
    {
        $base = [
            ['tipo' => 'estudiante', 'modo' => 'fecha_fija', 'mes' => 3, 'dia' => 31],
            ['tipo' => 'docente', 'modo' => 'fecha_fija', 'mes' => 3, 'dia' => 31],
            ['tipo' => 'funcionario', 'modo' => 'fecha_fija', 'mes' => 3, 'dia' => 31],
        ];
        foreach ($cambios as $tipo => $cambio) {
            $base = array_map(fn ($p) => $p['tipo'] === $tipo ? $cambio + ['tipo' => $tipo] : $p, $base);
        }

        return ['politicas' => $base, 'confirmado' => true];
    }

    public function test_por_defecto_los_tres_tipos_vencen_el_31_de_marzo_del_año_siguiente(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/vigencia')->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.tipo', 'estudiante')
            ->assertJsonPath('0.proximo', '2027-03-31')
            ->assertJsonPath('1.proximo', '2027-03-31')
            ->assertJsonPath('2.descripcion', 'Vence el 31 de marzo del año siguiente');
    }

    public function test_solo_el_administrador_cambia_las_politicas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->putJson('/api/vigencia', $this->politicas())->assertStatus(403);
    }

    public function test_los_docentes_pueden_durar_varios_años_y_los_funcionarios_no_vencer(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->putJson('/api/vigencia', $this->politicas([
            'docente' => ['modo' => 'duracion', 'anios' => 3],
            'funcionario' => ['modo' => 'sin_vencimiento'],
        ]))->assertOk()
            ->assertJsonPath('1.proximo', '2029-10-28')
            ->assertJsonPath('1.descripcion', 'Vence 3 años después de renovar')
            ->assertJsonPath('2.proximo', null)
            ->assertJsonPath('2.descripcion', 'No vence');

        $bitacora = Bitacora::where('accion', 'vigencia.actualizar')->sole();
        $this->assertStringContainsString('docente', $bitacora->descripcion);
        $this->assertStringContainsString('funcionario', $bitacora->descripcion);

        $docente = Usuario::factory()->create(['tipo' => 'docente', 'activo' => true, 'vigente_hasta' => '2026-03-31']);
        $funcionario = Usuario::factory()->create(['tipo' => 'funcionario', 'activo' => true, 'vigente_hasta' => '2026-03-31']);
        $estudiante = Usuario::factory()->create(['tipo' => 'estudiante', 'activo' => true, 'vigente_hasta' => '2026-03-31']);

        foreach ([$docente, $funcionario, $estudiante] as $usuario) {
            $this->patchJson("/api/usuarios/{$usuario->id}/habilitar")->assertOk();
        }

        $this->assertSame('2029-10-28', $docente->fresh()->vigente_hasta->toDateString());
        // Sin vencimiento: la cuenta queda activa y sin fecha.
        $this->assertNull($funcionario->fresh()->vigente_hasta);
        $this->assertTrue($funcionario->fresh()->activo);
        $this->assertSame('2027-03-31', $estudiante->fresh()->vigente_hasta->toDateString());
    }

    public function test_guardar_exige_confirmar_y_una_fecha_que_exista(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $sinConfirmar = $this->politicas();
        unset($sinConfirmar['confirmado']);
        $this->putJson('/api/vigencia', $sinConfirmar)->assertStatus(422)->assertJsonValidationErrors(['confirmado']);

        $this->putJson('/api/vigencia', $this->politicas(['docente' => ['modo' => 'fecha_fija', 'mes' => 2, 'dia' => 31]]))
            ->assertStatus(422)->assertJsonValidationErrors(['politicas.1.dia']);

        $this->putJson('/api/vigencia', $this->politicas(['docente' => ['modo' => 'duracion']]))
            ->assertStatus(422)->assertJsonValidationErrors(['politicas.1.anios']);

        $this->assertSame('fecha_fija', PoliticaVigencia::where('tipo', 'docente')->value('modo'));
    }

    public function test_la_carga_masiva_usa_la_politica_de_cada_tipo(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00');
        $admin = Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);
        PoliticaVigencia::where('tipo', 'docente')->update(['modo' => 'duracion', 'anios' => 2, 'mes' => null, 'dia' => null]);
        PoliticaVigencia::olvidar();

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '12.345.678-5', 'nombre' => 'Ana', 'apellido' => 'Pérez', 'tipo' => 'Docente'],
            ['fila' => 3, 'rut' => '15.003.151-6', 'nombre' => 'Luis', 'apellido' => 'Rojas', 'tipo' => 'Estudiante'],
        ]])->assertCreated();

        // Estudiante: de la carga de diciembre a marzo. Docente: dos años.
        $this->assertSame('2028-12-10', Usuario::where('rut', '12.345.678-5')->first()->vigente_hasta->toDateString());
        $this->assertSame('2027-03-31', Usuario::where('rut', '15.003.151-6')->first()->vigente_hasta->toDateString());
    }

    public function test_el_administrador_elige_otra_fecha_para_toda_la_carga(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $existente = Usuario::factory()->create(['rut' => '11.111.111-1', 'activo' => true, 'vigente_hasta' => '2026-12-31']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'vigente_hasta' => '2027-06-30', 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'nombre' => $existente->nombre, 'apellido' => $existente->apellido],
            ['fila' => 3, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona'],
            // La fila que trae su propia fecha conserva la suya.
            ['fila' => 4, 'rut' => '15.003.151-6', 'nombre' => 'Con', 'apellido' => 'Fecha', 'vigente_hasta' => '01/01/2028'],
        ]])->assertCreated();

        $this->assertSame('2027-06-30', $existente->fresh()->vigente_hasta->toDateString());
        $this->assertSame('2027-06-30', Usuario::where('rut', '12.345.678-5')->first()->vigente_hasta->toDateString());
        $this->assertSame('2028-01-01', Usuario::where('rut', '15.003.151-6')->first()->vigente_hasta->toDateString());
    }

    public function test_sin_el_permiso_de_vigencia_no_se_puede_cambiar_la_fecha_de_la_carga(): void
    {
        $rol = Rol::create(['clave' => 'cargador', 'nombre' => 'Cargador', 'nivel' => 3, 'permisos' => ['usuarios.gestionar'], 'acceso_total' => false]);
        Sanctum::actingAs(Staff::factory()->create(['rol' => $rol->clave]));

        $this->postJson('/api/usuarios/importar/revisar', ['vigente_hasta' => '2027-06-30', 'filas' => [
            ['fila' => 2, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona'],
        ]])->assertStatus(403);

        // Sin cambiar la fecha, esa misma persona sí puede cargar.
        $this->postJson('/api/usuarios/importar/revisar', ['filas' => [
            ['fila' => 2, 'rut' => '12.345.678-5', 'nombre' => 'Nueva', 'apellido' => 'Persona'],
        ]])->assertOk();
    }
}
