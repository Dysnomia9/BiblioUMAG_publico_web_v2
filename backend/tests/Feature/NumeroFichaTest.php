<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NumeroFichaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ficha_nueva_recibe_su_numero_y_se_devuelve_al_crearla(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $primera = $this->postJson('/api/libros', ['titulo' => 'Primera', 'codigo_barras' => null])->assertCreated()->json('numero_ficha');
        $segunda = $this->postJson('/api/libros', ['titulo' => 'Segunda', 'codigo_barras' => null])->assertCreated()->json('numero_ficha');

        $this->assertIsInt($primera);
        $this->assertSame($primera + 1, $segunda);
        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.catalogar', 'descripcion' => "Creó la ficha N.º {$segunda}, «Segunda», todavía sin ejemplares"]);
    }

    public function test_no_se_repite_ni_se_cambia_al_editar(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $libros = Libro::factory()->count(3)->create();
        $this->assertCount(3, $libros->pluck('id')->map(fn ($id) => Libro::find($id)->numero_ficha)->unique());

        $libro = $libros->first()->fresh();
        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => 'Otro título', 'numero_ficha' => 1])
            ->assertOk()
            ->assertJsonPath('numero_ficha', $libro->numero_ficha);
    }

    public function test_se_busca_por_numero_de_ficha_exacto(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $buscada = Libro::factory()->create(['titulo' => 'La buscada'])->fresh();
        Libro::factory()->create(['titulo' => 'Otra', 'clasificacion' => null, 'isbn' => null, 'otros_isbn' => null, 'issn' => null]);

        $titulos = fn (string $q) => collect($this->getJson('/api/libros?q='.urlencode($q))->assertOk()->json('data'))->pluck('titulo');

        $this->assertTrue($titulos((string) $buscada->numero_ficha)->contains('La buscada'));
        $this->assertTrue($titulos('#'.$buscada->numero_ficha)->contains('La buscada'));
        $this->assertFalse($titulos((string) $buscada->numero_ficha)->contains('Otra'));
    }

    public function test_nuevo_ejemplar_busca_solo_en_el_campo_elegido(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $sinNada = ['clasificacion' => null, 'otros_isbn' => null, 'issn' => null];
        Libro::factory()->create(['titulo' => 'Por ISBN', 'isbn' => '956-15-1167-4', ...$sinNada]);
        $porCodigo = Libro::factory()->create(['titulo' => 'Por código', 'isbn' => null, ...$sinNada]);
        Ejemplar::factory()->for($porCodigo)->create(['codigo_barras' => '30000000655316']);
        $porFicha = Libro::factory()->create(['titulo' => 'Por ficha', 'isbn' => null, ...$sinNada])->fresh();

        $titulos = fn (string $q, string $en) => collect($this->getJson('/api/libros?'.http_build_query(['q' => $q, 'buscar_en' => $en]))->assertOk()->json('data'))->pluck('titulo')->all();

        // Por N.º de ficha: solo esa, exacta; ni el ISBN ni el código ni el título
        $this->assertSame(['Por ficha'], $titulos((string) $porFicha->numero_ficha, 'ficha'));
        $this->assertSame([], $titulos('9561511674', 'ficha'));
        $this->assertSame([], $titulos('30000000655316', 'ficha'));
        $this->assertSame([], $titulos('Por', 'ficha'));
        // Todo el resto: ISBN, código de barras y nombre, pero no el N.º de ficha
        $this->assertSame(['Por ISBN'], $titulos('9561511674', 'resto'));
        $this->assertSame(['Por código'], $titulos('30000000655316', 'resto'));
        $this->assertSame(['Por código'], $titulos('Por código', 'resto'));
        $this->assertNotContains('Por ficha', $titulos((string) $porFicha->numero_ficha, 'resto'));
        // Copiado de la pantalla, con las letras: es esa ficha, se busque donde se busque
        foreach (["Ficha N.º {$porFicha->numero_ficha}", "ficha nº {$porFicha->numero_ficha}", "  N° {$porFicha->numero_ficha} ", "#{$porFicha->numero_ficha}", "Ficha {$porFicha->numero_ficha}"] as $pegado) {
            foreach (['ficha', 'resto', 'todo'] as $en) {
                $this->assertSame(['Por ficha'], $titulos($pegado, $en), "«{$pegado}» en {$en}");
            }
        }
        $this->assertContains('Por ISBN', $titulos('Por', 'todo'));
        $this->getJson('/api/libros?q=x&buscar_en=otra')->assertStatus(422);
    }

    public function test_las_colecciones_sugeridas_son_las_del_catalogo_por_cantidad_de_copias(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $general = Libro::factory()->create(['coleccion' => 'Coleccion general']);
        Ejemplar::factory()->for($general)->count(2)->create(['coleccion' => null]);
        Ejemplar::factory()->for(Libro::factory()->create(['coleccion' => 'Coleccion general']))->create(['coleccion' => 'Mapas']);
        Libro::factory()->create(['coleccion' => 'Testoteca']); // sin copias

        $this->getJson('/api/libros/colecciones')->assertOk()->assertExactJson([
            ['nombre' => 'Coleccion general', 'copias' => 2],
            ['nombre' => 'Mapas', 'copias' => 1],
            ['nombre' => 'Testoteca', 'copias' => 0],
        ]);
    }
}
