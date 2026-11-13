<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Autores, materias y áreas son decenas de miles con el catálogo real: se buscan por lo
 * que se escribe y llegan de a 20 (OpcionesCatalogo), en vez de la lista entera.
 */
class CatalogoLibroTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function nombres(string $ruta): array
    {
        return collect($this->getJson($ruta)->assertOk()->json())->pluck('nombre')->all();
    }

    public function test_sin_buscar_llegan_los_autores_mas_usados(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Autor::factory()->count(3)->create();
        Autor::factory()->create(['nombre' => 'Zúñiga, Rosa'])->libros()->attach(Libro::factory()->count(3)->create());
        Autor::factory()->create(['nombre' => 'Alfa'])->libros()->attach(Libro::factory()->create());

        $this->assertSame(['Zúñiga, Rosa', 'Alfa'], $this->nombres('/api/autores'));
    }

    public function test_sin_buscar_no_pasan_de_20(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();
        Autor::factory()->count(25)->create()->each(fn (Autor $autor) => $autor->libros()->attach($libro));

        $this->assertCount(20, $this->nombres('/api/autores'));
    }

    public function test_sugiere_entidades_de_otras_fichas_y_autores_registrados(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Libro::factory()->count(2)->create(['entidad_corporativa' => 'Universidad de Magallanes. Facultad de Ingeniería']);
        Libro::factory()->create(['entidad_secundaria' => 'Museo Regional de Magallanes; Universidad de Magallanes. Facultad de Ingeniería']);
        Autor::factory()->create(['nombre' => 'Universidad de Magallanes'])->libros()->attach(Libro::factory()->create());
        Autor::factory()->create(['nombre' => 'Neruda, Pablo']);

        // El 710 con varias cuenta cada una; la misma escrita en 110 y 710 suma, y el autor también aparece.
        $this->assertSame(
            ['Universidad de Magallanes. Facultad de Ingeniería', 'Museo Regional de Magallanes', 'Universidad de Magallanes'],
            $this->nombres('/api/entidades?q=magallanes'),
        );
        $this->assertSame(3, $this->getJson('/api/entidades?q=ingenieria')->json('0.libros'));
        $this->assertSame([], $this->nombres('/api/entidades'));
        $this->assertSame([], $this->nombres('/api/entidades?q=borges'));
    }

    public function test_el_listado_filtra_por_una_entidad_entera_en_el_110_o_el_710(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $autora = Libro::factory()->create(['titulo' => 'A', 'entidad_corporativa' => 'Universidad de Magallanes']);
        $relacionada = Libro::factory()->create(['titulo' => 'B', 'entidad_secundaria' => 'CEPAL; Universidad de Magallanes']);
        Libro::factory()->create(['titulo' => 'C', 'entidad_corporativa' => 'Universidad de Magallanes. Facultad de Ingeniería']);

        $ids = collect($this->getJson('/api/libros?entidad='.urlencode('universidad de magallanes'))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$autora->id, $relacionada->id], $ids);

        // En la ficha cada entidad del 710 se pulsa por separado.
        $fila = collect($this->getJson("/api/libros/{$relacionada->id}")->json('ficha'))->firstWhere('etiqueta', '710');
        $this->assertSame(['CEPAL', 'Universidad de Magallanes'], array_column($fila['items'] ?? [], 'entidad'));
    }

    public function test_busca_autores_por_cada_palabra_en_cualquier_orden_y_sin_tildes(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Autor::factory()->create(['nombre' => 'García Márquez, Gabriel']);
        Autor::factory()->create(['nombre' => 'Neruda, Pablo']);
        Autor::factory()->create(['nombre' => 'Mistral, Gabriela']);

        $this->assertSame(['García Márquez, Gabriel'], $this->nombres('/api/autores?q='.urlencode('marquez garcia')));
        $this->assertSame(['García Márquez, Gabriel', 'Mistral, Gabriela'], $this->nombres('/api/autores?q=gabriel'));
        $this->assertSame([], $this->nombres('/api/autores?q=borges'));
    }

    public function test_busca_categorias_y_no_pasa_de_20(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        foreach (range(1, 30) as $i) {
            Categoria::factory()->create(['nombre' => "Historia -- Siglo {$i}"]);
        }
        Categoria::factory()->create(['nombre' => 'Biología marina']);

        $this->assertCount(20, $this->nombres('/api/categorias?q=historia'));
        $this->assertSame(['Biología marina'], $this->nombres('/api/categorias?q=biologia'));
    }

    public function test_el_catalogo_de_carreras_no_trae_las_de_ejemplo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->assertSame([], $this->nombres('/api/carreras'));
    }

    public function test_al_catalogar_se_sugieren_tambien_las_carreras_de_los_usuarios(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $derecho = Carrera::create(['nombre' => 'Derecho']);
        Usuario::factory()->create(['carrera' => 'Derecho']);
        Usuario::factory()->create(['carrera' => 'DERECHO']);
        Usuario::factory()->create(['carrera' => 'KINESIOLOGIA']);
        Usuario::factory()->create(['carrera' => 'Kinesiología']);
        Usuario::factory()->create(['carrera' => 'Enfermería']);

        $this->getJson('/api/carreras?con_usuarios=1')->assertOk()->assertExactJson([
            ['id' => $derecho->id, 'nombre' => 'Derecho'],
            ['id' => null, 'nombre' => 'Enfermería'],
            ['id' => null, 'nombre' => 'Kinesiología'],
        ]);
        // Los filtros del listado siguen con las del catálogo, que tienen id
        $this->assertSame(['Derecho'], $this->nombres('/api/carreras'));
    }

    public function test_el_filtro_de_areas_del_portal_busca_solo_entre_las_que_tienen_libros_en_estante(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());
        $enEstante = Libro::factory()->create();
        Ejemplar::factory()->for($enEstante)->create(['estado_proceso' => 'en_estante']);
        $enProceso = Libro::factory()->create();
        Ejemplar::factory()->for($enProceso)->create(['estado_proceso' => 'procesos_tecnicos']);
        $enEstante->categorias()->attach(Categoria::factory()->create(['nombre' => 'Patagonia -- Historia']));
        $enProceso->categorias()->attach(Categoria::factory()->create(['nombre' => 'Patagonia -- Geología']));

        $this->assertSame(['Patagonia -- Historia'], $this->nombres('/api/mi/catalogo/areas?q=patagonia'));
        $this->assertSame(['Patagonia -- Historia'], $this->nombres('/api/mi/catalogo/areas'));
    }
}
