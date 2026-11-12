<?php

namespace Tests\Feature;

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
}
