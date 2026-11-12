<?php

namespace Tests\Feature;

use App\Models\Libro;
use App\Models\Staff;
use App\Support\FichaBibliografica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutorRolTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_autor_guarda_su_papel_y_la_ficha_lo_abrevia(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $this->postJson('/api/libros', [
            'codigo_barras' => 'UMAG000095',
            'titulo' => 'Harry Potter y la piedra filosofal',
            'serie' => 'Serie Harry Potter ; 1',
            'autores' => ['Rowling, J. K.', 'Dellepiane, Alicia'],
            'autores_roles' => [null, 'traductor'],
        ])->assertStatus(201);

        $libro = Libro::with('autores', 'categorias', 'carreras', 'tipoMaterial')->first();
        $this->assertSame([null, 'traductor'], $libro->autores->pluck('pivot.rol')->all());

        $ficha = collect(FichaBibliografica::ficha($libro, true));
        $this->assertSame('Rowling, J. K.', $ficha->firstWhere('etiqueta', '100')['valor']);
        $this->assertSame(['campo' => 'Traductores', 'valor' => 'Dellepiane, Alicia'], collect($ficha->firstWhere('etiqueta', '700'))->only('campo', 'valor')->all());
        $this->assertSame('(Serie Harry Potter ; 1)', $ficha->firstWhere('etiqueta', '490')['valor']);
    }

    public function test_una_obra_compilada_no_tiene_autor_principal(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->postJson('/api/libros', [
            'titulo' => 'A bibliography on marine and estuarine oil pollution',
            'autores' => ['Moulder, David S.', 'Varley, Allen'],
            'autores_roles' => ['compilador', 'compilador'],
        ])->assertStatus(201);

        $ficha = collect(FichaBibliografica::ficha(Libro::with('autores', 'categorias', 'carreras', 'tipoMaterial')->first(), true));
        $this->assertNull($ficha->firstWhere('etiqueta', '100'));
        $this->assertSame('Moulder, David S.; Varley, Allen', $ficha->firstWhere('campo', 'Compiladores')['valor']);
    }

    public function test_los_secundarios_van_en_una_fila_por_papel(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->postJson('/api/libros', [
            'titulo' => 'Obra mixta',
            'autores' => ['Pérez, Ana', 'Soto, Juan', 'Díaz, Eva', 'Lara, Pía'],
            'autores_roles' => [null, null, 'traductor', 'traductor'],
        ])->assertStatus(201);

        $filas = collect(FichaBibliografica::ficha(Libro::with('autores', 'categorias', 'carreras', 'tipoMaterial')->first(), true))
            ->whereIn('etiqueta', ['100', '700'])->map(fn ($f) => [$f['etiqueta'], $f['campo'], $f['valor']])->values()->all();

        $this->assertSame([
            ['100', 'Autor principal', 'Pérez, Ana'],
            ['700', 'Autores secundarios', 'Soto, Juan'],
            ['700', 'Traductores', 'Díaz, Eva; Lara, Pía'],
        ], $filas);
    }

    public function test_la_ficha_entrega_cada_autor_y_profesor_por_separado_y_se_busca_por_profesor(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->postJson('/api/libros', [
            'titulo' => 'Tesis de prueba',
            'autores' => ['Ursic Biott, Joyceline', 'Vidal Huenchur, Laura'],
            'profesor_guia' => 'Sirón Ramírez, Rodrigo',
        ])->assertStatus(201);
        $libro = Libro::with('autores', 'categorias', 'carreras', 'tipoMaterial')->first();

        $secundarios = collect(FichaBibliografica::ficha($libro, true))->firstWhere('campo', 'Autores secundarios');
        $this->assertSame([['texto' => 'Vidal Huenchur, Laura', 'autor_id' => $libro->autores[1]->id]], $secundarios['items']);

        $this->getJson('/api/libros?q=siron')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_un_papel_desconocido_no_se_acepta(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $this->postJson('/api/libros', [
            'codigo_barras' => 'UMAG000094',
            'titulo' => 'Otro',
            'autores' => ['Alguien'],
            'autores_roles' => ['astronauta'],
        ])->assertStatus(422)->assertJsonValidationErrors('autores_roles.0');
    }
}
