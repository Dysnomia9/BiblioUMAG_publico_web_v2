<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «el», «de» y las palabras de 1 o 2 letras no filtran nada y son las más lentas (los
 * índices de trigramas no sirven con menos de 3 letras): se descartan si queda otra.
 */
class BusquedaPalabrasVaciasTest extends TestCase
{
    use RefreshDatabase;

    public function test_descarta_las_palabras_vacias_si_queda_otra(): void
    {
        $this->assertSame(['puerto', 'talcahuano'], Busqueda::palabras('el puerto de talcahuano'));
        $this->assertSame(['Historia', 'region', 'Magallanes'], Busqueda::palabras('Historia DE LA region de Magallanes'));
        $this->assertSame(['manual', 'docentes'], Busqueda::palabras('manual para docentes'));
        $this->assertSame(['química', 'orgánica'], Busqueda::palabras('química y orgánica en'));
    }

    public function test_si_todas_son_cortas_se_busca_tal_cual(): void
    {
        $this->assertSame(['de'], Busqueda::palabras('de'));
        $this->assertSame(['ph'], Busqueda::palabras('ph'));
        $this->assertSame(['el', 'de'], Busqueda::palabras('el de'));
    }

    public function test_conserva_las_cortas_con_digitos_o_simbolos(): void
    {
        $this->assertSame(['calculo', '2'], Busqueda::palabras('calculo 2'));
        $this->assertSame(['harry', 'potter', 'v1'], Busqueda::palabras('harry potter v1'));
        $this->assertSame(['c#', 'programacion'], Busqueda::palabras('c# programacion'));
        $this->assertSame(['c++'], Busqueda::palabras('el c++'));
        $this->assertSame(['tomo', 'v2'], Busqueda::palabras('tomo v2'));
    }

    public function test_quita_la_puntuacion_pegada_y_sobre(): void
    {
        $this->assertSame(['Canumán', 'Chacón', 'José', 'Diego'], Busqueda::palabras('Canumán Chacón, José Diego'));
        $this->assertSame(['ensayo', 'física'], Busqueda::palabras('«ensayo» sobre la física.'));
        $this->assertSame(['Qué', 'hacer'], Busqueda::palabras('¿Qué hacer?'));
        $this->assertSame(['c++'], Busqueda::palabras('(c++)'));
        $this->assertSame(['O.C'], Busqueda::palabras('O.C.'));
        $this->assertSame(['de'], Busqueda::palabras('de,'));
    }

    public function test_una_palabra_sin_digitos_no_busca_en_codigos_isbn_ni_issn(): void
    {
        $sinDigitos = strtolower(BusquedaLibros::ids('talcahuano', BusquedaLibros::CAMPOS_PERSONAL)->toSql());
        foreach (['issn', 'isbn', 'ejemplares'] as $columna) {
            $this->assertStringNotContainsString($columna, $sinDigitos, "«talcahuano» no debería consultar {$columna}");
        }
        // La clasificación sí: hay revistas ubicadas por un nombre sin dígitos («Acanthus»)
        $this->assertStringContainsString('clasificacion', $sinDigitos);

        $conDigitos = strtolower(BusquedaLibros::ids('35606200182220', BusquedaLibros::CAMPOS_PERSONAL)->toSql());
        foreach (['issn', 'isbn', 'ejemplares', 'clasificacion'] as $columna) {
            $this->assertStringContainsString($columna, $conDigitos);
        }
    }

    public function test_todas_las_palabras_tienen_que_aparecer_en_el_panel_y_el_portal(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Historia de Magallanes']);
        $libro->autores()->attach(Autor::factory()->create(['nombre' => 'Canumán Chacón, José Diego']));
        $soloUna = Libro::factory()->create(['titulo' => 'Historia de Chile']);
        foreach ([$libro, $soloUna] as $l) {
            Ejemplar::factory()->for($l)->create();
        }

        Sanctum::actingAs(Staff::factory()->create());
        foreach (['Canumán Chacón, José Diego', 'magallanes chacon', 'historia jose'] as $busqueda) {
            $ids = collect($this->getJson('/api/libros?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "panel: «{$busqueda}»");
        }
        $this->assertCount(2, $this->getJson('/api/libros?q=historia')->assertOk()->json('data'));

        Sanctum::actingAs(Usuario::factory()->create());
        foreach (['Canumán Chacón, José Diego', 'magallanes chacon'] as $busqueda) {
            $ids = collect($this->getJson('/api/mi/catalogo?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "portal: «{$busqueda}»");
        }
    }

    public function test_el_listado_encuentra_igual_con_o_sin_palabras_vacias(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'El puerto de Talcahuano']);
        Libro::factory()->create(['titulo' => 'El puerto de Valparaíso']);

        foreach (['el puerto de talcahuano', 'puerto talcahuano', 'EL PUERTO DE TALCAHUANO'] as $busqueda) {
            $ids = collect($this->getJson('/api/libros?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "buscando «{$busqueda}»");
        }
    }

    public function test_el_portal_encuentra_igual_con_o_sin_palabras_vacias(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'El puerto de Talcahuano']);
        $otro = Libro::factory()->create(['titulo' => 'El puerto de Valparaíso']);
        Ejemplar::factory()->for($libro)->create();
        Ejemplar::factory()->for($otro)->create();
        Sanctum::actingAs(Usuario::factory()->create());

        foreach (['el puerto de talcahuano', 'puerto talcahuano', 'EL PUERTO DE TALCAHUANO'] as $busqueda) {
            $ids = collect($this->getJson('/api/mi/catalogo?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "buscando «{$busqueda}»");
        }
        // Solo palabras cortas: se busca tal cual
        $this->assertCount(2, $this->getJson('/api/mi/catalogo?q=de')->assertOk()->json('data'));
    }

    public function test_los_codigos_se_siguen_encontrando(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Química general', 'isbn' => '978-956-7189-50-1']);
        $copia = Ejemplar::factory()->for($libro)->create(['codigo_barras' => '35606200182220']);
        Libro::factory()->create(['titulo' => 'Química orgánica']);

        foreach (['35606200182220', 'quimica 182220', '978-956-7189', (string) $copia->fresh()->codigo_lomo] as $busqueda) {
            $ids = collect($this->getJson('/api/libros?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "buscando «{$busqueda}»");
        }
    }
}
