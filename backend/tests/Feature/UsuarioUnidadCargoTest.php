<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Docentes y funcionarios no tienen carrera: se los ubica por su unidad y su cargo. */
class UsuarioUnidadCargoTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_crea_un_docente_con_unidad_y_cargo(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $id = $this->postJson('/api/usuarios', [
            'rut' => '12.345.678-5', 'nombre' => 'Ana', 'apellido' => 'Pérez', 'tipo' => 'docente',
            'unidad' => 'Departamento de Ciencias', 'cargo' => 'Profesor adjunto',
        ])->assertCreated()->json('id');

        $docente = Usuario::find($id);
        $this->assertSame(['docente', 'Departamento de Ciencias', 'Profesor adjunto', null], [$docente->tipo, $docente->unidad, $docente->cargo, $docente->carrera]);
    }

    public function test_la_planilla_trae_unidad_y_cargo_y_los_actualiza(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $existente = Usuario::factory()->create(['rut' => '11.111.111-1', 'tipo' => 'funcionario', 'carrera' => null, 'unidad' => null]);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'unidad' => 'BIBLIOTECA CENTRAL', 'cargo' => 'Bibliotecaria'],
            ['fila' => 3, 'rut' => '12.345.678-5', 'nombre' => 'LUIS', 'apellido' => 'ROJAS', 'tipo' => 'Docente', 'unidad' => 'Facultad de Humanidades'],
        ]])->assertCreated();

        $this->assertSame(['Biblioteca Central', 'Bibliotecaria'], [$existente->fresh()->unidad, $existente->fresh()->cargo]);
        $nuevo = Usuario::where('rut', '12.345.678-5')->first();
        $this->assertSame(['docente', 'Facultad de Humanidades'], [$nuevo->tipo, $nuevo->unidad]);
    }

    public function test_por_carrera_agrupa_a_docentes_y_funcionarios_por_su_unidad(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $conUnidad = Usuario::factory()->create(['tipo' => 'docente', 'carrera' => null, 'unidad' => 'Departamento de Ciencias']);
        $sinUnidad = Usuario::factory()->create(['tipo' => 'docente', 'carrera' => null, 'unidad' => null]);
        $funcionario = Usuario::factory()->create(['tipo' => 'funcionario', 'carrera' => null, 'unidad' => 'Biblioteca']);

        foreach ([$conUnidad, $conUnidad, $sinUnidad, $funcionario] as $usuario) {
            Prestamo::factory()->create(['usuario_id' => $usuario->id, 'tipo_item' => 'libro']);
        }

        $porCarrera = collect($this->getJson('/api/reportes/resumen?tab=prestamos')->assertOk()->json('porCarrera'))->pluck('value', 'label');

        $this->assertSame(2, $porCarrera['Docentes · Departamento de Ciencias']);
        $this->assertSame(1, $porCarrera['Docentes (sin carrera)']);
        $this->assertSame(1, $porCarrera['Funcionarios · Biblioteca']);
    }
}
