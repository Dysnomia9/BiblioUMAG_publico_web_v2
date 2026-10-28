<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalCatalogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_catalogo_va_de_la_a_a_la_z_y_deja_los_signos_al_final(): void
    {
        foreach (['¿Qué es la vida?', 'zoología marina', '[Mapa de Magallanes]', 'Álgebra lineal', '1984', 'Biología', '¡Socorro!'] as $titulo) {
            Ejemplar::factory()->for(Libro::factory()->create(['titulo' => $titulo]))->create();
        }
        Sanctum::actingAs(Usuario::factory()->create());

        $titulos = array_column($this->getJson('/api/mi/catalogo')->assertOk()->json('data'), 'titulo');

        $this->assertSame(['Álgebra lineal', 'Biología', 'zoología marina', '1984'], array_slice($titulos, 0, 4));
        $this->assertEqualsCanonicalizing(['¿Qué es la vida?', '[Mapa de Magallanes]', '¡Socorro!'], array_slice($titulos, 4));
    }

    public function test_el_catalogo_del_portal_busca_por_isbn_con_o_sin_guiones(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Flora de la Patagonia austral', 'isbn' => '978-956-7189-50-1']);
        Ejemplar::factory()->for($libro)->create();
        Ejemplar::factory()->for(Libro::factory()->create(['isbn' => '978-0-00-000000-0']))->create();

        Sanctum::actingAs(Usuario::factory()->create());

        foreach (['9789567189501', '978-956-7189'] as $busqueda) {
            $this->getJson('/api/mi/catalogo?q='.$busqueda)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $libro->id);
        }
    }

    public function test_el_catalogo_del_portal_busca_palabras_sueltas_en_cualquier_orden(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Introducción a la Programación en Python']);
        Ejemplar::factory()->for($libro)->create();
        Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Introducción al Derecho Civil']))->create();

        Sanctum::actingAs(Usuario::factory()->create());

        foreach (['intro python', 'python introduccion', 'programacion INTRO'] as $busqueda) {
            $this->getJson('/api/mi/catalogo?q='.urlencode($busqueda))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $libro->id);
        }
    }

    public function test_un_libro_prestado_informa_la_devolucion_mas_proxima(): void
    {
        $libro = Libro::factory()->create();
        foreach ([9, 4] as $dias) {
            $copia = Ejemplar::factory()->for($libro)->create(['disponible' => false]);
            Prestamo::factory()->create(['ejemplar_id' => $copia->id, 'fecha_devolucion' => now()->addDays($dias)]);
        }
        $devuelta = Ejemplar::factory()->for($libro)->create(['disponible' => false]);
        Prestamo::factory()->create(['ejemplar_id' => $devuelta->id, 'estado' => 'devuelto', 'fecha_devolucion' => now()->addDay()]);

        $disponible = Libro::factory()->create();
        Ejemplar::factory()->for($disponible)->create();

        Sanctum::actingAs(Usuario::factory()->create());
        $catalogo = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(now()->addDays(4)->toDateString(), $catalogo[$libro->id]['proxima_devolucion']);
        $this->assertNull($catalogo[$disponible->id]['proxima_devolucion']);
    }
}
