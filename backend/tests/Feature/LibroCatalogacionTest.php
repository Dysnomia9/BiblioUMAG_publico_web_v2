<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibroCatalogacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_puede_catalogar_un_libro_con_codigo_de_barras_ya_usado(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $existente = Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => '7501234567890']);

        $response = $this->postJson('/api/libros', [
            'codigo_barras' => $existente->codigo_barras,
            'titulo' => 'Otro título cualquiera',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('codigo_barras');

        $this->assertSame(1, Ejemplar::where('codigo_barras', $existente->codigo_barras)->count());
    }

    public function test_catalogar_libro_nuevo_crea_la_obra_y_su_primer_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->postJson('/api/libros', [
            'codigo_barras' => 'UMAG000099',
            'titulo' => 'Título de prueba',
            'isbn' => '9781234567897',
            'autores' => ['Autor Nuevo'],
            'categorias' => ['Categoría Nueva'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('titulo', 'Título de prueba')
            ->assertJsonPath('isbn', '9781234567897')
            ->assertJsonPath('ejemplares.0.codigo_barras', 'UMAG000099')
            ->assertJsonPath('ejemplares.0.numero_copia', 1)
            ->assertJsonPath('ejemplares.0.estado_proceso', 'inventario')
            ->assertJsonPath('autores.0.nombre', 'Autor Nuevo')
            ->assertJsonPath('categorias.0.nombre', 'Categoría Nueva');

        $this->assertDatabaseHas('autores', ['nombre' => 'Autor Nuevo']);
        $this->assertDatabaseHas('categorias', ['nombre' => 'Categoría Nueva']);
    }

    public function test_catalogar_libro_reutiliza_autor_existente_en_vez_de_duplicarlo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $autor = Autor::factory()->create(['nombre' => 'Autor Repetido']);

        $this->postJson('/api/libros', [
            'codigo_barras' => 'UMAG000098',
            'titulo' => 'Otro título',
            'autores' => ['Autor Repetido'],
        ])->assertStatus(201);

        $this->assertSame(1, Autor::where('nombre', 'Autor Repetido')->count());
        $this->assertTrue(Libro::first()->autores->contains($autor->id));
    }

    public function test_isbn_duplicado_falla_validacion(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        Libro::factory()->create(['isbn' => '9781234567897']);

        $response = $this->postJson('/api/libros', [
            'codigo_barras' => 'UMAG000097',
            'titulo' => 'Título cualquiera',
            'isbn' => '9781234567897',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('isbn');
    }

    public function test_actualizar_un_libro_edita_los_campos_bibliograficos_sin_tocar_los_ejemplares(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create(['titulo' => 'Título original']);
        $ejemplar = Ejemplar::factory()->for($libro)->create(['codigo_barras' => 'UMAG000096']);

        $response = $this->patchJson("/api/libros/{$libro->id}", [
            'titulo' => 'Título actualizado',
        ]);

        $response->assertStatus(200)->assertJsonPath('titulo', 'Título actualizado');
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'codigo_barras' => 'UMAG000096']);
    }

    public function test_actualizar_un_libro_sincroniza_sus_categorias(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create();
        $libro->categorias()->attach(Categoria::factory()->create(['nombre' => 'Categoría Vieja']));

        $this->patchJson("/api/libros/{$libro->id}", [
            'titulo' => $libro->titulo,
            'categorias' => ['Categoría Nueva'],
        ])->assertStatus(200);

        $libro->refresh();
        $this->assertSame(['Categoría Nueva'], $libro->categorias->pluck('nombre')->all());
    }

    public function test_codigo_de_barras_duplicado_en_ejemplares_falla_a_nivel_de_base_de_datos(): void
    {
        Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => '7501234567890']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => '7501234567890']);
    }

    public function test_agregar_copia_con_codigo_de_barras_ya_usado_falla_validacion(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create();
        $existente = Ejemplar::factory()->for($libro)->create(['codigo_barras' => 'UMAG000095']);

        $response = $this->postJson('/api/ejemplares', [
            'libro_id' => $libro->id,
            'codigo_barras' => $existente->codigo_barras,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
    }

    public function test_agregar_copia_calcula_el_siguiente_numero_de_copia(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create();
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 1, 'codigo_barras' => 'UMAG000094']);

        $response = $this->postJson('/api/ejemplares', [
            'libro_id' => $libro->id,
            'codigo_barras' => 'UMAG000093',
        ]);

        $response->assertStatus(201)->assertJsonPath('numero_copia', 2);
        $this->assertSame(2, Ejemplar::where('libro_id', $libro->id)->count());
    }

    public function test_siguiente_codigo_de_barras_empieza_en_1_sin_datos(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $response = $this->getJson('/api/ejemplares/siguiente-codigo-barras');

        $response->assertStatus(200)->assertJsonPath('codigo_barras', '00000000000001');
    }

    public function test_siguiente_codigo_de_barras_continua_la_secuencia(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => '30000003227565']);
        Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => '30000003227560']);

        $response = $this->getJson('/api/ejemplares/siguiente-codigo-barras');

        $response->assertStatus(200)->assertJsonPath('codigo_barras', '30000003227566');
    }

    public function test_siguiente_codigo_de_barras_ignora_codigos_que_no_son_14_digitos(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        Ejemplar::factory()->for(Libro::factory())->create(['codigo_barras' => 'UMAG000099']);

        $response = $this->getJson('/api/ejemplares/siguiente-codigo-barras');

        $response->assertStatus(200)->assertJsonPath('codigo_barras', '00000000000001');
    }

    public function test_staff_no_admin_no_puede_generar_codigo_de_barras(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->getJson('/api/ejemplares/siguiente-codigo-barras');

        $response->assertStatus(403);
    }
}
