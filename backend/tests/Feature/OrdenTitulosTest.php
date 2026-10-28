<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El Listado y el Historial de material del personal ordenan los títulos como el catálogo
 * del portal: de la A a la Z, y al final lo que empieza con un número o un signo.
 */
class OrdenTitulosTest extends TestCase
{
    use RefreshDatabase;

    private const ESPERADO = ['Álgebra lineal', 'Biología', 'zoología marina', '1984'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['"A través de las artes"', '!Hagamos títeres!', 'zoología marina', 'Álgebra lineal', '1984', 'Biología', '¿Qué es la vida?'] as $titulo) {
            Ejemplar::factory()->for(Libro::factory()->create(['titulo' => $titulo]))->create();
        }
        Sanctum::actingAs(Staff::factory()->create());
    }

    private function assertOrden(array $titulos): void
    {
        $this->assertSame(self::ESPERADO, array_slice($titulos, 0, 4));
        $this->assertEqualsCanonicalizing(['"A través de las artes"', '!Hagamos títeres!', '¿Qué es la vida?'], array_slice($titulos, 4));
    }

    public function test_el_listado_de_material_va_de_la_a_a_la_z_con_los_signos_al_final(): void
    {
        $this->assertOrden(array_column($this->getJson('/api/libros')->assertOk()->json('data'), 'titulo'));

        // Ordenado por tipo de material, dentro de cada tipo vale lo mismo.
        $this->assertOrden(array_column($this->getJson('/api/libros?orden=tipo_material')->assertOk()->json('data'), 'titulo'));
    }

    public function test_el_historial_de_material_tambien(): void
    {
        $this->assertOrden(array_column($this->getJson('/api/libros/historial')->assertOk()->json('data.*.libro'), 'titulo'));
    }
}
