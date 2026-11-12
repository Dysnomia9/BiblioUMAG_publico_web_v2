<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** El pie de imprenta lleva la fecha como la escribió Horizon («c2001», «[19--]»), no solo el año. */
class PieImprentaFechaTest extends TestCase
{
    use RefreshDatabase;

    private function pie(array $atributos): ?string
    {
        $libro = Libro::factory()->create(['lugar_publicacion' => 'Philadelphia', 'editorial' => 'Lippincott&Wilkins', ...$atributos]);
        Ejemplar::factory()->for($libro)->create();
        Sanctum::actingAs(Usuario::factory()->create());
        $ficha = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $libro->id)['ficha'];

        return collect($ficha)->firstWhere('campo', 'Pie de imprenta')['valor'] ?? null;
    }

    public function test_la_fecha_como_se_escribio(): void
    {
        $this->assertSame('Philadelphia : Lippincott&Wilkins, c2001.', $this->pie(['anio_publicacion' => 2001, 'fecha_publicacion' => 'c2001']));
    }

    public function test_fecha_supuesta_sin_anio(): void
    {
        $this->assertSame('Philadelphia : Lippincott&Wilkins, [19--].', $this->pie(['anio_publicacion' => null, 'fecha_publicacion' => '[19--]']));
    }

    public function test_si_el_anio_se_corrigio_manda_el_anio(): void
    {
        $this->assertSame('Philadelphia : Lippincott&Wilkins, 2005.', $this->pie(['anio_publicacion' => 2005, 'fecha_publicacion' => 'c2001']));
    }

    public function test_sin_fecha_escrita_queda_el_anio(): void
    {
        $this->assertSame('Philadelphia : Lippincott&Wilkins, 2001.', $this->pie(['anio_publicacion' => 2001, 'fecha_publicacion' => null]));
    }
}
