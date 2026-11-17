<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecepcionInterbibliotecariaCompletaTest extends TestCase
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

    private function recepcion(array $cambios = []): array
    {
        return array_replace([
            'ficha' => ['titulo' => 'Manual recibido'],
            'ejemplares' => [['codigo_barras' => '30000009991101']],
            'biblioteca' => 'Biblioteca propietaria',
            'fecha_ingreso' => '2026-11-04',
            'fecha_limite' => '2026-11-30',
            'nota' => 'Solicitud de la escuela',
        ], $cambios);
    }

    public function test_la_ficha_completa_y_el_ejemplar_conservan_sus_datos_y_su_numero(): void
    {
        $ficha = [
            'titulo' => 'Manual recibido', 'titulo_alternativo' => "Otro título\nManual de consulta",
            'isbn' => '9789560001234', 'otros_isbn' => '9789560001235', 'clasificacion' => '610 M294 2026',
            'autores' => ['Pérez, Ana', 'Soto, Juan'], 'autores_roles' => [null, 'traductor'],
            'categorias' => ['Salud--Manuales'], 'carreras' => ['Medicina'],
            'coleccion' => 'General', 'edicion' => '2a. ed.', 'editorial' => 'Ediciones de prueba',
            'lugar_publicacion' => 'Punta Arenas', 'anio_publicacion' => 2026, 'idioma' => 'Español',
            'descripcion_fisica' => '320 p. : il.', 'serie' => 'Manuales ; 4', 'contenido' => 't.1: Bases -- t.2: Aplicaciones',
            'nota_publica' => 'Edición ampliada', 'nota_interna' => 'Ficha interna',
            'resumen' => 'Texto del resumen completo', 'mencion_responsabilidad' => 'Ana Pérez ; traducción de Juan Soto',
        ];
        $r = $this->postJson('/api/prestamos-interbibliotecarios', $this->recepcion([
            'ficha' => $ficha,
            'ejemplares' => [[
                'codigo_barras' => '30000009991101', 'tomo' => 't.2-v.II', 'copia' => 3,
                'coleccion' => 'Consulta', 'numero_registro' => 'REG-001', 'precio' => 12000,
                'nota_publica' => 'Incluye mapas', 'nota_interna' => 'Ejemplar interno',
            ]],
        ]))->assertCreated()->assertJsonPath('cantidad', 1)->assertJsonPath('ejemplar.estado_proceso', 'por_colocar');
        $libro = Libro::sole();
        $copia = Ejemplar::sole();
        $this->assertIsInt($r->json('numero_ficha'));
        $this->assertSame($libro->numero_ficha, $r->json('ejemplar.libro.numero_ficha'));
        foreach (['titulo_alternativo', 'isbn', 'otros_isbn', 'clasificacion', 'coleccion', 'edicion', 'editorial',
            'lugar_publicacion', 'anio_publicacion', 'idioma', 'contenido', 'nota_publica', 'nota_interna', 'resumen', 'mencion_responsabilidad'] as $campo) {
            $this->assertSame($ficha[$campo], $libro->{$campo}, $campo);
        }
        $this->assertSame(['Salud -- Manuales'], $libro->categorias->pluck('nombre')->all());
        $this->assertSame(['Medicina'], $libro->carreras->pluck('nombre')->all());
        $this->assertSame([null, 'traductor'], $libro->autores->pluck('pivot.rol')->all());
        $this->assertSame(['t.2-v.II', 3, 't.2-v.II c.3', 'Consulta', 'REG-001', 'Incluye mapas', 'Ejemplar interno'],
            [$copia->tomo, $copia->copia_tomo, $copia->volumen, $copia->coleccion, $copia->numero_registro, $copia->nota_publica, $copia->nota_interna]);
        $this->getJson('/api/prestamos-interbibliotecarios?q='.urlencode('Ficha N.º '.$libro->numero_ficha))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.ejemplar.libro.numero_ficha', $libro->numero_ficha);
    }

    public function test_varios_ejemplares_reutilizan_la_ficha_existente_sin_modificarla(): void
    {
        $propia = Ejemplar::factory()->create();
        $antes = $propia->libro->fresh()->getAttributes();
        $datos = $this->recepcion([
            'libro_id' => $propia->libro_id,
            'ejemplares' => [['codigo_barras' => '10000199'], ['codigo_barras' => '30000009991102']],
        ]);
        unset($datos['ficha']);
        $r = $this->postJson('/api/prestamos-interbibliotecarios', $datos)->assertCreated()
            ->assertJsonPath('cantidad', 2)->assertJsonCount(2, 'registros');
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('ejemplares', 3);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 2);
        $this->assertSame($antes, $propia->libro->fresh()->getAttributes());
        $this->assertSame('10000199', $r->json('registros.0.ejemplar.codigo_lomo'));
        $this->assertSame($r->json('numero_ficha'), $r->json('registros.1.ejemplar.libro.numero_ficha'));
        $this->assertSame(1, $r->json('registros.0.ejemplar.copia_tomo'));
        $this->assertSame(2, $r->json('registros.1.ejemplar.copia_tomo'));
    }

    public function test_una_copia_repetida_revierte_la_recepcion_completa(): void
    {
        $this->postJson('/api/prestamos-interbibliotecarios', $this->recepcion([
            'ejemplares' => [
                ['codigo_barras' => '30000009991101', 'tomo' => 't.1', 'copia' => 1],
                ['codigo_barras' => '30000009991102', 'tomo' => 't.1', 'copia' => 1],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('ejemplares.1.copia');
        $this->assertDatabaseCount('libros', 0);
        $this->assertDatabaseCount('ejemplares', 0);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
    }

    public function test_los_codigos_duplicados_se_validan_antes_de_crear_la_ficha(): void
    {
        $this->postJson('/api/prestamos-interbibliotecarios', $this->recepcion([
            'ejemplares' => [['codigo_barras' => ' DUPLICADO '], ['codigo_barras' => 'DUPLICADO']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['ejemplares.0.codigo_barras', 'ejemplares.1.codigo_barras']);
        $this->assertDatabaseCount('libros', 0);
    }

    public function test_el_isbn_existente_no_crea_otra_ficha(): void
    {
        Libro::factory()->create(['isbn' => '9789560001234']);
        $this->postJson('/api/prestamos-interbibliotecarios', $this->recepcion([
            'ficha' => ['titulo' => 'Duplicado', 'isbn' => '9789560001234'],
        ]))->assertStatus(422)->assertJsonValidationErrors('ficha.isbn');
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('ejemplares', 0);
    }

    public function test_se_validan_los_campos_completos_y_no_se_guardan_datos_invalidos(): void
    {
        $this->postJson('/api/prestamos-interbibliotecarios', $this->recepcion([
            'ficha' => ['titulo' => 'Inválido', 'anio_publicacion' => 0, 'url_acceso' => 'sin-url', 'autores' => ['Ana'], 'autores_roles' => ['inexistente']],
            'ejemplares' => [['codigo_barras' => '30000009991101', 'numero_registro' => str_repeat('x', 51)]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['ficha.anio_publicacion', 'ficha.url_acceso', 'ficha.autores_roles.0', 'ejemplares.0.numero_registro']);
        $this->assertDatabaseCount('libros', 0);
        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
    }
}
