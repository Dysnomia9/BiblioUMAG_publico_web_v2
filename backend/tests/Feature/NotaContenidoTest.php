<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use App\Support\FichaBibliografica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Nota de contenido (MARC 505): índice de capítulos o volúmenes. */
class NotaContenidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_505_esta_en_las_plantillas_de_libro_y_de_trabajo_de_titulacion_y_es_publica(): void
    {
        $campos = collect(FichaBibliografica::plantillas())->mapWithKeys(fn ($p) => [$p['clave'] => collect($p['campos'])->firstWhere('clave', 'contenido')]);

        foreach (['libro', 'trabajo_titulacion'] as $plantilla) {
            $this->assertSame(['clave' => 'contenido', 'etiqueta' => '505', 'nombre' => 'Nota de contenido', 'publico' => true], $campos[$plantilla]);
        }
        $this->assertNull($campos['juego_educativo']);
        $this->assertNull($campos['publicacion_seriada']);
    }

    public function test_se_cataloga_y_se_edita_la_nota_de_contenido(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $libro = $this->postJson('/api/libros', [
            'codigo_barras' => '30000000000002',
            'titulo' => 'Cálculo',
            'contenido' => 'Límites -- Derivadas -- Integrales',
        ])->assertCreated()
            ->assertJsonPath('contenido', 'Límites -- Derivadas -- Integrales')
            ->json();

        $this->patchJson("/api/libros/{$libro['id']}", ['titulo' => 'Cálculo', 'contenido' => 'v. 1. Límites -- v. 2. Integrales'])
            ->assertOk()
            ->assertJsonPath('contenido', 'v. 1. Límites -- v. 2. Integrales')
            ->assertJsonFragment(['etiqueta' => '505', 'campo' => 'Nota de contenido', 'valor' => 'v. 1. Límites -- v. 2. Integrales', 'interno' => false]);
    }

    public function test_el_portal_muestra_la_nota_de_contenido(): void
    {
        $libro = Libro::factory()->create(['contenido' => 'Cap. 1 Introducción -- Cap. 2 Métodos']);
        Ejemplar::factory()->for($libro)->create();

        Sanctum::actingAs(Usuario::factory()->create());

        $this->getJson('/api/mi/catalogo')->assertOk()
            ->assertJsonFragment(['etiqueta' => '505', 'valor' => 'Cap. 1 Introducción -- Cap. 2 Métodos']);
    }

    public function test_un_juego_educativo_no_muestra_la_nota_de_contenido(): void
    {
        $tipo = TipoMaterial::factory()->create(['plantilla' => 'juego_educativo']);
        $libro = Libro::factory()->create(['tipo_material_id' => $tipo->id, 'contenido' => 'No va']);

        $this->assertNotContains('505', array_column(FichaBibliografica::ficha($libro, conInternos: true), 'etiqueta'));
    }
}
