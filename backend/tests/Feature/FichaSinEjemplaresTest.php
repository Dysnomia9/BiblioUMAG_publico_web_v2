<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FichaSinEjemplaresTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_crea_la_ficha_sin_codigo_de_barras_y_sin_copias(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $this->postJson('/api/libros', ['titulo' => 'Ficha madre', 'codigo_barras' => null])
            ->assertStatus(201)
            ->assertJsonPath('titulo', 'Ficha madre');

        $libro = Libro::first();
        $this->assertSame(0, $libro->ejemplares()->count());
    }

    public function test_se_cataloga_sin_titulo_y_queda_sin_titulo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $this->postJson('/api/libros', ['titulo' => '', 'codigo_barras' => null])
            ->assertStatus(201)
            ->assertJsonPath('titulo', Libro::SIN_TITULO);
        $this->postJson('/api/libros', ['codigo_barras' => null])
            ->assertStatus(201)
            ->assertJsonPath('titulo', Libro::SIN_TITULO);

        $libro = Libro::factory()->create(['titulo' => 'Con nombre']);
        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => ''])
            ->assertOk()
            ->assertJsonPath('titulo', Libro::SIN_TITULO);
        // Lo que no se manda no se toca
        $otro = Libro::factory()->create(['titulo' => 'Se queda']);
        $this->patchJson("/api/libros/{$otro->id}", ['edicion' => '2a. ed.'])->assertOk();
        $this->assertSame('Se queda', $otro->fresh()->titulo);
    }

    public function test_una_ficha_sin_ejemplares_no_se_ve_en_el_portal(): void
    {
        $sinCopias = Libro::factory()->create(['titulo' => 'Sin copias']);
        $conCopia = Libro::factory()->create(['titulo' => 'Con copia']);
        Ejemplar::factory()->for($conCopia)->create(['estado_proceso' => 'en_estante']);

        Sanctum::actingAs(Usuario::factory()->create());
        $titulos = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->pluck('titulo');

        $this->assertTrue($titulos->contains('Con copia'));
        $this->assertFalse($titulos->contains('Sin copias'));
    }

    public function test_el_libro_electronico_se_busca_en_el_portal_pero_no_se_reserva(): void
    {
        $tipo = TipoMaterial::firstOrCreate(['nombre' => 'Libro electrónico']);
        $ebook = Libro::factory()->create(['titulo' => 'Ebook de prueba', 'tipo_material_id' => $tipo->id, 'url_acceso' => 'https://ebsco.example/libro']);

        Sanctum::actingAs(Usuario::factory()->create());
        $fila = collect($this->getJson('/api/mi/catalogo?q=ebook')->assertOk()->json('data'))->firstWhere('id', $ebook->id);

        $this->assertNotNull($fila);
        $this->assertTrue($fila['digital']);
        $this->assertSame('https://ebsco.example/libro', $fila['url_acceso']);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ebook->id])->assertStatus(404);
    }

    public function test_el_primer_ejemplar_de_una_ficha_es_la_copia_0_y_no_se_repiten(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->postJson('/api/libros', ['titulo' => 'Ficha madre'])->assertStatus(201);
        $libro = Libro::first();

        $this->postJson("/api/ejemplares", ["libro_id" => $libro->id, 'codigo_barras' => '30000000000101'])->assertSuccessful();
        $this->postJson("/api/ejemplares", ["libro_id" => $libro->id, 'codigo_barras' => '30000000000102'])->assertSuccessful();
        $this->assertSame([0, 1], $libro->ejemplares()->orderBy('numero_copia')->pluck('numero_copia')->all());

        $this->expectException(QueryException::class);
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 1]);
    }
}
