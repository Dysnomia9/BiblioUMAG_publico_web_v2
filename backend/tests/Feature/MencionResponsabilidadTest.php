<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La mención de responsabilidad (245 $c), como en el bib# 56012 de Horizon: la segunda
 * autora solo figura ahí, sin un 700. Se ve en la ficha y se encuentra al buscar.
 */
class MencionResponsabilidadTest extends TestCase
{
    use RefreshDatabase;

    private function libro(): Libro
    {
        $libro = Libro::factory()->create([
            'titulo' => 'A functional approach to group work in occupational therapy',
            'mencion_responsabilidad' => 'Margot C. Howe, Shara L. Schwartzberg',
        ]);
        $libro->autores()->attach(Autor::factory()->create(['nombre' => 'Howe, Margot C.']));
        Ejemplar::factory()->for($libro)->create();
        Libro::factory()->create(['titulo' => 'Otro libro']);

        return $libro;
    }

    public function test_se_ve_en_la_ficha_tras_el_titulo(): void
    {
        $libro = $this->libro();
        Sanctum::actingAs(Usuario::factory()->create());

        $ficha = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $libro->id)['ficha'];
        $campos = array_column($ficha, 'campo');
        $fila = collect($ficha)->firstWhere('campo', 'Mención de responsabilidad');

        $this->assertSame('245', $fila['etiqueta']);
        $this->assertSame('Margot C. Howe, Shara L. Schwartzberg', $fila['valor']);
        $this->assertSame(array_search('Título', $campos) + 1, array_search('Mención de responsabilidad', $campos));
    }

    /** @return list<string> los campos de la ficha del portal */
    private function camposDe(array $libro, array $autores, ?string $guia = null): array
    {
        $l = Libro::factory()->create([...$libro, 'profesor_guia' => $guia]);
        foreach ($autores as $nombre) {
            $l->autores()->attach(Autor::factory()->create(['nombre' => $nombre]));
        }
        Ejemplar::factory()->for($l)->create();
        Sanctum::actingAs(Usuario::factory()->create());

        return array_column(collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $l->id)['ficha'], 'campo');
    }

    public function test_no_se_muestra_si_repite_a_los_autores(): void
    {
        // bib# 61233: las dos personas ya están en el 100 y el 700
        $this->assertNotContains('Mención de responsabilidad', $this->camposDe(
            ['titulo' => 'Accidentabilidad laboral', 'mencion_responsabilidad' => 'Celina Carrasco, Jesús Donari'],
            ['Carrasco, Celina', 'Donari, Jesús'],
        ));
        // bib# 4123: «compiled by … and …», los dos como compiladores
        $this->assertNotContains('Mención de responsabilidad', $this->camposDe(
            ['titulo' => 'A bibliography on marine oil pollution', 'mencion_responsabilidad' => 'compiled by David S. Moulder and Allen Varley'],
            ['Moulder, David S.', 'Varley, Allen'],
        ));
    }

    public function test_no_se_muestra_en_la_tesis_con_profesor_guia(): void
    {
        $this->assertNotContains('Mención de responsabilidad', $this->camposDe(
            ['titulo' => 'Aplicación del densímetro no nuclear', 'mencion_responsabilidad' => 'Cristian Hernán Ojeda Muñoz, Bastián Alfredo Andrés Pérez Torrealba ; profesor guía: Srta. Berta Vivar Vivar, Ingeniero Civil en Obras Civiles'],
            ['Ojeda Muñoz, Cristian Hernán', 'Pérez Torrealba, Bastián Alfredo Andrés'],
            'Vivar Vivar, Berta Marianela',
        ));
    }

    public function test_se_muestra_si_nombra_a_un_traductor_no_catalogado(): void
    {
        $this->assertContains('Mención de responsabilidad', $this->camposDe(
            ['titulo' => 'Marketing', 'mencion_responsabilidad' => 'Philip Kotler ; traducción: Andrés Mateo'],
            ['Kotler, Philip, 1931-'],
        ));
    }

    public function test_se_encuentra_en_el_panel_y_en_el_portal(): void
    {
        $libro = $this->libro();

        Sanctum::actingAs(Staff::factory()->create());
        $ids = collect($this->getJson('/api/libros?q=schwartzberg')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$libro->id], $ids);

        Sanctum::actingAs(Usuario::factory()->create());
        $ids = collect($this->getJson('/api/mi/catalogo?q=group+schwartzberg')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$libro->id], $ids);
    }

    public function test_se_guarda_al_catalogar(): void
    {
        $libro = $this->libro();
        Sanctum::actingAs(Staff::factory()->create());

        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'mencion_responsabilidad' => 'Margot C. Howe'])->assertOk();
        $this->assertSame('Margot C. Howe', $libro->fresh()->mencion_responsabilidad);
    }
}
