<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReporteResumenLibrosSalasTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_libros_rankea_por_titulo_prestado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $masPrestado = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'El más prestado']))->create();
        $menosPrestado = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'El menos prestado']))->create();

        Prestamo::factory()->count(3)->create([
            'ejemplar_id' => $masPrestado->id,
            'tipo_item' => 'libro',
            'libro_titulo' => 'El más prestado',
        ]);
        Prestamo::factory()->create([
            'ejemplar_id' => $menosPrestado->id,
            'tipo_item' => 'libro',
            'libro_titulo' => 'El menos prestado',
        ]);

        $response = $this->getJson('/api/reportes/resumen?tab=libros');

        $response->assertStatus(200);
        $porLibro = $response->json('porLibro');
        $this->assertSame('El más prestado', $porLibro[0]['label']);
        $this->assertSame(3, $porLibro[0]['value']);
    }

    public function test_tab_prestamos_no_incluye_porlibro(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $response = $this->getJson('/api/reportes/resumen?tab=prestamos');

        $response->assertStatus(200)->assertJsonPath('porLibro', []);
    }

    public function test_tab_prestamos_separa_recurso_bibliografico_y_recursos_de_apoyo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Prestamo::factory()->count(4)->create(['tipo_item' => 'libro']);
        Prestamo::factory()->count(2)->create(['tipo_item' => 'notebook']);
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $this->getJson('/api/reportes/resumen?tab=prestamos')
            ->assertOk()
            ->assertJsonPath('total', 7)
            ->assertJsonPath('porTipoPrestamo', [
                ['label' => 'bibliografico', 'value' => 4],
                ['label' => 'recursos_apoyo', 'value' => 3],
            ])
            ->assertJsonPath('porRecurso', [
                ['label' => 'Sin tipo de material', 'value' => 4],
                ['label' => 'notebook', 'value' => 2],
                ['label' => 'audifonos', 'value' => 1],
            ]);

        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_prestamo=recursos_apoyo')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('porTipoPrestamo', [['label' => 'recursos_apoyo', 'value' => 3]]);

        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_prestamo=bibliografico')
            ->assertOk()
            ->assertJsonPath('total', 4);

        // En las otras pestañas el desglose va vacío.
        $this->getJson('/api/reportes/resumen?tab=libros')
            ->assertOk()
            ->assertJsonPath('porTipoPrestamo', [])
            ->assertJsonPath('porRecurso', []);
    }

    public function test_los_reportes_separan_los_juegos_de_los_libros_por_tipo_de_material(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $libro = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Cálculo', 'tipo_material_id' => TipoMaterial::where('nombre', 'Libro')->value('id')]))->create();
        $juegoTipo = TipoMaterial::firstOrCreate(['nombre' => 'Juego educativo'], ['plantilla' => 'juego_educativo']);
        $juego = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Dominó de fracciones', 'tipo_material_id' => $juegoTipo->id]))->create();

        Prestamo::factory()->count(3)->create(['ejemplar_id' => $libro->id, 'tipo_item' => 'libro', 'libro_titulo' => 'Cálculo']);
        Prestamo::factory()->count(2)->create(['ejemplar_id' => $juego->id, 'tipo_item' => 'libro', 'libro_titulo' => 'Dominó de fracciones']);
        Prestamo::factory()->create(['tipo_item' => 'notebook']);

        $this->getJson('/api/reportes/resumen?tab=prestamos')
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('porRecurso', [
                ['label' => 'Libro', 'value' => 3],
                ['label' => 'Juego educativo', 'value' => 2],
                ['label' => 'notebook', 'value' => 1],
            ])
            ->assertJsonPath('porTipoMaterial', [
                ['label' => 'Libro', 'value' => 3],
                ['label' => 'Juego educativo', 'value' => 2],
            ]);

        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_material='.$juegoTipo->id)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('porTipoMaterial', [['label' => 'Juego educativo', 'value' => 2]]);

        $this->getJson('/api/reportes/resumen?tab=libros&tipo_material='.$juegoTipo->id)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('porLibro', [['label' => 'Dominó de fracciones', 'value' => 2]]);

        $this->getJson('/api/reportes/opciones')
            ->assertOk()
            ->assertJsonFragment(['id' => $juegoTipo->id, 'nombre' => 'Juego educativo']);
    }

    public function test_por_carrera_junta_las_variantes_y_no_llama_sin_dato_a_docentes_ni_funcionarios(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $derecho = Usuario::factory()->create(['tipo' => 'estudiante', 'carrera' => 'Derecho']);
        $derechoMayusculas = Usuario::factory()->create(['tipo' => 'estudiante', 'carrera' => 'DERECHO']);
        $psicologia = Usuario::factory()->create(['tipo' => 'estudiante', 'carrera' => 'PSICOLOGIA']);
        $docente = Usuario::factory()->create(['tipo' => 'docente', 'carrera' => null]);
        $funcionario = Usuario::factory()->create(['tipo' => 'funcionario', 'carrera' => null]);
        $sinCarrera = Usuario::factory()->create(['tipo' => 'estudiante', 'carrera' => null]);

        foreach ([$derecho, $derecho, $derechoMayusculas, $psicologia, $docente, $docente, $docente, $funcionario, $sinCarrera] as $usuario) {
            Prestamo::factory()->create(['usuario_id' => $usuario->id, 'tipo_item' => 'libro']);
        }

        $porCarrera = collect($this->getJson('/api/reportes/resumen?tab=prestamos')->assertOk()->json('porCarrera'))->pluck('value', 'label');

        // «Derecho» y «DERECHO» son la misma carrera; «PSICOLOGIA» se muestra con nombre propio.
        $this->assertSame(3, $porCarrera['Derecho']);
        $this->assertArrayNotHasKey('DERECHO', $porCarrera->all());
        $this->assertSame(1, $porCarrera['Psicologia']);
        $this->assertSame(3, $porCarrera['Docentes (sin carrera)']);
        $this->assertSame(1, $porCarrera['Funcionarios (sin carrera)']);
        $this->assertSame(1, $porCarrera['Estudiantes sin carrera registrada']);
        $this->assertArrayNotHasKey('Sin dato', $porCarrera->all());
    }

    public function test_tab_logias_rankea_por_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $salaPopular = Sala::factory()->create(['nombre' => 'Logia Popular', 'codigo' => 'LPO']);
        $salaTranquila = Sala::factory()->create(['nombre' => 'Logia Tranquila', 'codigo' => 'LTR']);

        Reserva::factory()->count(2)->create(['sala_id' => $salaPopular->id, 'usuario_id' => Usuario::factory()]);
        Reserva::factory()->create(['sala_id' => $salaTranquila->id, 'usuario_id' => Usuario::factory()]);

        $response = $this->getJson('/api/reportes/resumen?tab=logias');

        $response->assertStatus(200);
        $porSala = $response->json('porSala');
        $this->assertSame('LPO · Logia Popular', $porSala[0]['label']);
        $this->assertSame(2, $porSala[0]['value']);
    }

    public function test_tab_logias_desglosa_horas_por_cada_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $logiaA = Sala::factory()->create(['nombre' => 'Logia A', 'codigo' => 'LA']);
        $logiaB = Sala::factory()->create(['nombre' => 'Logia B', 'codigo' => 'LB']);

        Reserva::factory()->create(['sala_id' => $logiaA->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
        Reserva::factory()->create(['sala_id' => $logiaA->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
        Reserva::factory()->create(['sala_id' => $logiaB->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);

        $response = $this->getJson('/api/reportes/resumen?tab=logias');

        $response->assertStatus(200);
        $porHoraPorSala = collect($response->json('porHoraPorSala'))->keyBy('sala');

        $horasA = collect($porHoraPorSala['LA · Logia A']['horas'])->keyBy('label');
        $this->assertSame(2, $horasA['10h']['value']);
        $this->assertSame(0, $horasA['16h']['value']);

        $horasB = collect($porHoraPorSala['LB · Logia B']['horas'])->keyBy('label');
        $this->assertSame(1, $horasB['16h']['value']);
        $this->assertSame(0, $horasB['10h']['value']);
    }

    public function test_tab_prestamos_no_incluye_porhoraporsala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $response = $this->getJson('/api/reportes/resumen?tab=prestamos');

        $response->assertStatus(200)->assertJsonPath('porHoraPorSala', []);
    }
}
