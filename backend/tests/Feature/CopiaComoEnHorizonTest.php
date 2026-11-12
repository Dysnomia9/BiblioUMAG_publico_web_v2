<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La copia se nombra con su «c.N» de Horizon (el del lomo), no con numero_copia, que es interno
 * de BiblioUMAG: en «Geriatría clínica» la c.2 tiene numero_copia 3, y salía como «Copia 3».
 */
class CopiaComoEnHorizonTest extends TestCase
{
    use RefreshDatabase;

    private function geriatria(): Ejemplar
    {
        $libro = Libro::factory()->create(['titulo' => 'Geriatría clínica']);
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 1]);
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 2, 'copia_tomo' => 1]);
        $c2 = Ejemplar::factory()->for($libro)->create(['numero_copia' => 3, 'copia_tomo' => 2, 'codigo_barras' => '35606100398060']);
        Ejemplar::whereKey($c2->id)->update(['fecha_ingreso_horizon' => '2011-04-18']);

        return $c2->fresh();
    }

    public function test_la_c2_de_horizon_se_llama_copia_2(): void
    {
        $c2 = $this->geriatria();

        $this->assertSame('c.2', $c2->etiqueta_copia);
        $this->assertSame('copia 2', $c2->copiaEnTexto());
        $this->assertSame('Geriatría clínica (Copia 2)', $c2->load('libro')->tituloConCopia());

        Sanctum::actingAs(Staff::factory()->create());
        $this->getJson('/api/ejemplares/35606100398060')->assertOk()->assertJsonPath('etiqueta_copia', 'c.2');
    }

    public function test_con_columnas_a_medias_no_inventa_el_numero_interno(): void
    {
        $c2 = $this->geriatria();

        // Sin copia_tomo ni fecha_ingreso_horizon no se sabe su «c.N»: null, no «c.3»
        $this->assertNull(Ejemplar::whereKey($c2->id)->first(['id', 'numero_copia'])->etiqueta_copia);
        $this->assertSame('c.2', Ejemplar::whereKey($c2->id)->first(['id', 'numero_copia', 'copia_tomo', 'fecha_ingreso_horizon'])->etiqueta_copia);
    }

    public function test_la_copia_pegada_al_tomo_se_reconoce(): void
    {
        // Como figuran en Horizon y en los lomos impresos (6 copias)
        $this->assertSame(['t.1', 1], \App\Support\TomoEjemplar::desdeEtiqueta('t.1c.1'));
        $this->assertSame(['v.1', 2], \App\Support\TomoEjemplar::desdeEtiqueta('v.1c.2'));
        $this->assertSame(['t.2', 1], \App\Support\TomoEjemplar::desdeEtiqueta('t.2/c.1'));
        // Lo de siempre no cambia
        $this->assertSame(['t.2a', null], \App\Support\TomoEjemplar::desdeEtiqueta('t.2a'));
        $this->assertSame(['v.2', 1], \App\Support\TomoEjemplar::desdeEtiqueta('v.2-c.1'));
        $this->assertSame(['t.1', 3], \App\Support\TomoEjemplar::desdeEtiqueta('t.1 c.3'));
        $this->assertSame([null, null], \App\Support\TomoEjemplar::desdeEtiqueta('No. 367 (Ene., 2014)'));
    }

    public function test_sin_c_de_horizon_no_lleva_numero(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Tesis']);
        $copia = Ejemplar::factory()->for($libro)->create(['numero_copia' => 2]);
        Ejemplar::whereKey($copia->id)->update(['fecha_ingreso_horizon' => '2011-04-18']);
        $copia = $copia->fresh();

        $this->assertNull($copia->etiqueta_copia);
        $this->assertSame('copia sin número', $copia->copiaEnTexto());
        $this->assertSame('Tesis', $copia->load('libro')->tituloConCopia());
    }
}
