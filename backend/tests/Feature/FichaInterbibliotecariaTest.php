<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\FichaInterbibliotecaria;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\PrestamoInterbibliotecario;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FichaInterbibliotecariaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        Sanctum::actingAs(Staff::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(array $cambios = []): array
    {
        return array_replace([
            'ficha' => ['titulo' => 'Obra de otra biblioteca', 'isbn' => '9789560009876',
                'clasificacion' => '610 O24', 'contenido' => 't.1: Bases -- t.2: Casos',
                'autores' => ['Pérez, Ana'], 'autores_roles' => ['traductor'], 'categorias' => ['Medicina']],
            'biblioteca' => 'Biblioteca propietaria', 'fecha_ingreso' => '2026-11-04',
            'fecha_limite' => '2026-11-30', 'nota' => 'Debe regresar a su propietaria',
        ], $cambios);
    }

    private function crearFicha(): int
    {
        return $this->postJson('/api/prestamos-interbibliotecarios/fichas', $this->datos())->assertCreated()->json('id');
    }

    public function test_crea_ficha_completa_con_plazo_sin_generar_ejemplares_y_la_lista_aunque_este_vacia(): void
    {
        $id = $this->crearFicha();
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('fichas_interbibliotecarias', 1);
        $this->assertDatabaseCount('ejemplares', 0);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
        $this->getJson('/api/prestamos-interbibliotecarios/fichas/'.$id)->assertOk()
            ->assertJsonPath('libro.contenido', 't.1: Bases -- t.2: Casos')
            ->assertJsonPath('libro.autores.0.pivot.rol', 'traductor')
            ->assertJsonPath('fecha_limite', '2026-11-30')->assertJsonPath('ingresos_count', 0);
        $n = Libro::sole()->numero_ficha;
        $this->getJson('/api/prestamos-interbibliotecarios/fichas?q='.urlencode('Ficha N.º '.$n))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
    }

    public function test_edita_la_ficha_y_la_devolucion_sin_exigir_copias_y_sin_duplicar_isbn(): void
    {
        $id = $this->crearFicha();
        $numero = Libro::sole()->numero_ficha;
        $datos = $this->datos(['fecha_limite' => '2026-12-15', 'biblioteca' => 'Biblioteca Central']);
        $datos['ficha']['titulo'] = 'Título corregido';
        $this->patchJson('/api/prestamos-interbibliotecarios/fichas/'.$id, $datos)->assertOk()
            ->assertJsonPath('libro.titulo', 'Título corregido')->assertJsonPath('libro.numero_ficha', $numero)
            ->assertJsonPath('biblioteca', 'Biblioteca Central')->assertJsonPath('fecha_limite', '2026-12-15');
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('ejemplares', 0);
    }

    public function test_agregar_despues_usa_el_plazo_actual_de_la_ficha_y_no_el_enviado_por_el_cliente(): void
    {
        $id = $this->crearFicha();
        $this->patchJson('/api/prestamos-interbibliotecarios/fichas/'.$id, $this->datos(['fecha_limite' => '2026-12-15']))->assertOk();
        $r = $this->postJson('/api/prestamos-interbibliotecarios', [
            'ficha_interbibliotecaria_id' => $id, 'fecha_limite' => '2099-12-31', 'biblioteca' => 'Incorrecta',
            'ejemplares' => [['codigo_barras' => '30000009998001'], ['codigo_barras' => '30000009998002', 'nota_interna' => 'Solo esta copia']],
        ])->assertCreated()->assertJsonPath('cantidad', 2)->assertJsonPath('biblioteca', 'Biblioteca propietaria')
            ->assertJsonPath('fecha_limite', '2026-12-15')->assertJsonPath('fecha_tope', '2026-12-14')
            ->assertJsonPath('ficha_interbibliotecaria_id', $id)->assertJsonPath('registros.1.ejemplar.nota_interna', 'Solo esta copia');
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('fichas_interbibliotecarias', 1);
        $this->getJson('/api/prestamos-interbibliotecarios/fichas/'.$id)->assertJsonPath('ingresos_count', 2)->assertJsonPath('pendientes_count', 2);
        $p = PrestamoInterbibliotecario::find($r->json('id'));
        $this->assertNotNull($p->motivoRechazo(Carbon::parse('2026-12-15')));
    }

    public function test_editar_plazo_actualiza_solo_las_copias_pendientes_y_conserva_prestamos_e_historial(): void
    {
        $id = $this->crearFicha();
        $r = $this->postJson('/api/prestamos-interbibliotecarios', ['ficha_interbibliotecaria_id' => $id,
            'ejemplares' => [['codigo_barras' => '30000009998001'], ['codigo_barras' => '30000009998002']]])->assertCreated();
        $pendiente = PrestamoInterbibliotecario::find($r->json('registros.0.id'));
        $devuelto = PrestamoInterbibliotecario::find($r->json('registros.1.id'));
        $devuelto->update(['devuelto_el' => '2026-11-04']);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $pendiente->ejemplar_id, 'fecha_devolucion' => '2026-11-20']);
        $antes = $prestamo->fresh()->getAttributes();
        $this->patchJson('/api/prestamos-interbibliotecarios/fichas/'.$id, $this->datos(['fecha_limite' => '2026-12-15']))->assertOk();
        $this->assertSame('2026-12-15', $pendiente->fresh()->fecha_limite->toDateString());
        $this->assertSame('2026-11-30', $devuelto->fresh()->fecha_limite->toDateString());
        $this->assertSame($antes, $prestamo->fresh()->getAttributes());
    }

    public function test_un_lote_invalido_no_borra_ni_modifica_la_ficha_previamente_guardada(): void
    {
        $id = $this->crearFicha();
        $antes = FichaInterbibliotecaria::find($id)->getAttributes();
        $this->postJson('/api/prestamos-interbibliotecarios', ['ficha_interbibliotecaria_id' => $id,
            'ejemplares' => [['codigo_barras' => '30000009998001', 'tomo' => 't.1', 'copia' => 1],
                ['codigo_barras' => '30000009998002', 'tomo' => 't.1', 'copia' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('ejemplares.1.copia');
        $this->assertSame($antes, FichaInterbibliotecaria::find($id)->getAttributes());
        $this->assertDatabaseCount('ejemplares', 0);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
        $this->assertDatabaseCount('libros', 1);
    }

    public function test_la_ficha_exige_biblioteca_y_fecha_de_devolucion_y_rechaza_crear_copias_en_ese_paso(): void
    {
        $this->postJson('/api/prestamos-interbibliotecarios/fichas', $this->datos([
            'fecha_limite' => '2026-11-01', 'biblioteca' => '', 'ejemplares' => [['codigo_barras' => '30000009998001']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['fecha_limite', 'biblioteca', 'ejemplares']);
        $this->assertDatabaseCount('libros', 0);
        $this->assertDatabaseCount('fichas_interbibliotecarias', 0);
    }

    public function test_puede_reutilizar_un_titulo_existente_sin_modificar_su_ficha_ni_copias_propias(): void
    {
        $ejemplar = Ejemplar::factory()->create();
        $libro = $ejemplar->libro;
        $antes = $libro->getAttributes();
        $datos = $this->datos(['libro_id' => $libro->id]);
        unset($datos['ficha']);
        $this->postJson('/api/prestamos-interbibliotecarios/fichas', $datos)->assertCreated()->assertJsonPath('libro_id', $libro->id);
        $this->assertSame($antes, $libro->fresh()->getAttributes());
        $this->assertDatabaseCount('ejemplares', 1);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
    }

    public function test_no_permite_asociar_la_ficha_a_otro_titulo_al_agregar_copias(): void
    {
        $id = $this->crearFicha();
        $otro = Libro::factory()->create();
        $this->postJson('/api/prestamos-interbibliotecarios', ['ficha_interbibliotecaria_id' => $id,
            'libro_id' => $otro->id, 'ejemplares' => [['codigo_barras' => '30000009998001']]])
            ->assertUnprocessable()->assertJsonValidationErrors('libro_id');
        $this->assertDatabaseCount('ejemplares', 0);
    }

    public function test_la_migracion_agrupa_los_ingresos_anteriores_sin_perder_copias_ni_devoluciones(): void
    {
        $libro = Libro::factory()->create();
        $copias = Ejemplar::factory()->count(2)->create(['libro_id' => $libro->id]);
        foreach ($copias as $i => $copia) {
            PrestamoInterbibliotecario::create(['ejemplar_id' => $copia->id, 'biblioteca' => 'Biblioteca anterior',
                'fecha_ingreso' => '2026-11-02', 'fecha_limite' => '2026-11-30', 'devuelto_el' => $i ? '2026-11-04' : null]);
        }
        $migracion = require database_path('migrations/2026_11_16_000002_fichas_interbibliotecarias_independientes.php');
        $migracion->down();
        $migracion->up();
        $this->assertDatabaseCount('fichas_interbibliotecarias', 1);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 2);
        $this->assertDatabaseCount('ejemplares', 2);
        $this->assertSame(1, PrestamoInterbibliotecario::distinct()->count('ficha_interbibliotecaria_id'));
        $this->assertSame(1, PrestamoInterbibliotecario::whereNotNull('devuelto_el')->count());
    }

    public function test_el_editor_encuentra_el_prestamo_por_codigo_exacto_de_barras_o_lomo(): void
    {
        $id = $this->crearFicha();
        $r = $this->postJson('/api/prestamos-interbibliotecarios', ['ficha_interbibliotecaria_id' => $id,
            'ejemplares' => [['codigo_barras' => '30000009998001'], ['codigo_barras' => '30000009998002']]])->assertCreated();
        $registro = $r->json('registros.1');
        foreach (['30000009998002', $registro['ejemplar']['codigo_lomo']] as $codigo) {
            $this->getJson('/api/prestamos-interbibliotecarios?vista=todos&por_pagina=1&codigo='.urlencode($codigo))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $registro['id']);
        }
        $this->getJson('/api/prestamos-interbibliotecarios?codigo=300000099980')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/prestamos-interbibliotecarios?codigo[]=invalido')->assertUnprocessable();
    }
}
