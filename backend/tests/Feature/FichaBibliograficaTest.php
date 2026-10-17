<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FichaBibliograficaTest extends TestCase
{
    use RefreshDatabase;

    private function libroEnEstante(string $tipo, array $campos): Libro
    {
        $libro = Libro::factory()->create([
            'tipo_material_id' => TipoMaterial::where('nombre', $tipo)->value('id'),
            ...$campos,
        ]);
        Ejemplar::factory()->for($libro)->create();

        return $libro;
    }

    private function fichaEnElPortal(Libro $libro): array
    {
        Sanctum::actingAs(Usuario::factory()->create());

        return collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $libro->id)['ficha'];
    }

    public function test_el_portal_muestra_solo_las_etiquetas_publicas_de_un_libro(): void
    {
        $catalogador = Staff::factory()->create(['nombre' => 'Carolina Muñoz']);
        $libro = $this->libroEnEstante('Libro', [
            'titulo' => 'Historia de la Patagonia',
            'isbn' => '978-956-00-1234-5',
            'clasificacion' => '982.64 M385h 2021',
            'lugar_publicacion' => 'Punta Arenas',
            'editorial' => 'Ediciones UMAG',
            'anio_publicacion' => 2021,
            'descripcion_fisica' => '350 p. : il. ; 24 cm',
            'url_acceso' => 'https://biblioteca.umag.cl/recurso/123',
            'idioma' => 'Español',
            'serie' => 'Colección Patagonia',
            'nota_interna' => 'Ejemplar donado por la Facultad',
            'nota_bibliografia' => 'Incluye referencias bibliográficas',
            'catalogado_por_staff_id' => $catalogador->id,
        ]);
        $libro->autores()->attach([
            Autor::create(['nombre' => 'Martinic, Mateo'])->id => ['orden' => 1],
            Autor::create(['nombre' => 'Ortega, Luis'])->id => ['orden' => 2],
        ]);

        $ficha = collect($this->fichaEnElPortal($libro))->pluck('valor', 'campo');

        $this->assertSame('978-956-00-1234-5', $ficha['ISBN']);
        $this->assertSame('982.64 M385h 2021', $ficha['Número de clasificación Dewey']);
        $this->assertSame('Martinic, Mateo', $ficha['Autor principal']);
        $this->assertSame('Ortega, Luis', $ficha['Autores secundarios']);
        $this->assertSame('Punta Arenas : Ediciones UMAG, 2021', $ficha['Pie de imprenta']);
        $this->assertSame('https://biblioteca.umag.cl/recurso/123', $ficha['Acceso electrónico']);
        foreach (['Idioma', 'Serie', 'Notas generales', 'Nota de bibliografía', 'Catalogador', 'Creación del registro'] as $interno) {
            $this->assertArrayNotHasKey($interno, $ficha->all());
        }

        $respuesta = $this->getJson('/api/mi/catalogo')->getContent();
        $this->assertStringNotContainsString('nota_interna', $respuesta);
        $this->assertStringNotContainsString('Ejemplar donado por la Facultad', $respuesta);
        $this->assertStringNotContainsString('Carolina', $respuesta);
    }

    public function test_un_trabajo_de_titulacion_usa_clasificacion_local_nota_de_tesis_y_profesor_guia(): void
    {
        $libro = $this->libroEnEstante('Tesis', [
            'clasificacion' => 'T 005.1 C764s 2023',
            'nota_tesis' => 'Tesis (Ingeniero Civil en Computación e Informática) -- Universidad de Magallanes, 2023',
            'profesor_guia' => 'Pérez, Ana',
            'nota_bibliografia' => 'Bibliografía: h. 80-85',
        ]);

        $ficha = collect($this->fichaEnElPortal($libro));

        $this->assertSame('099', $ficha->firstWhere('campo', 'Número de clasificación local')['etiqueta']);
        $this->assertNotNull($ficha->firstWhere('campo', 'Nota de tesis'));
        $this->assertSame('Pérez, Ana', $ficha->firstWhere('campo', 'Profesor guía')['valor']);
        $this->assertNull($ficha->firstWhere('campo', 'Nota de bibliografía'));
    }

    public function test_una_revista_muestra_issn_y_volumen_pero_no_frecuencia_ni_procedencia(): void
    {
        $libro = $this->libroEnEstante('Revista', [
            'issn' => '0718-3542',
            'designacion_volumen' => 'Vol. 12, n.º 3 (2024)',
            'frecuencia' => 'Semestral',
            'nota_local' => 'Canje con la Universidad de Chile',
            'nota_idioma' => 'Resúmenes en inglés',
        ]);

        $ficha = collect($this->fichaEnElPortal($libro))->pluck('valor', 'etiqueta');

        $this->assertSame('0718-3542', $ficha['022']);
        $this->assertSame('Vol. 12, n.º 3 (2024)', $ficha['362']);
        foreach (['310', '546', '590'] as $interna) {
            $this->assertArrayNotHasKey($interna, $ficha->all());
        }
    }

    public function test_un_juego_educativo_muestra_produccion_resumen_y_audiencia(): void
    {
        $libro = $this->libroEnEstante('Juego educativo', [
            'lugar_publicacion' => 'Santiago',
            'editorial' => 'Didácticos Austral',
            'anio_publicacion' => 2022,
            'resumen' => 'Juego de cartas para aprender la flora magallánica.',
            'audiencia' => 'Niños de 8 a 12 años',
        ]);

        $ficha = collect($this->fichaEnElPortal($libro))->keyBy('etiqueta');

        $this->assertSame('Producción y publicación', $ficha['264']['campo']);
        $this->assertSame('Santiago : Didácticos Austral, 2022', $ficha['264']['valor']);
        $this->assertSame('Niños de 8 a 12 años', $ficha['521']['valor']);
        $this->assertArrayHasKey('520', $ficha->all());
    }

    public function test_el_personal_ve_la_ficha_completa_con_los_campos_internos_marcados(): void
    {
        $catalogador = Staff::factory()->create(['nombre' => 'Carolina Muñoz']);
        $libro = $this->libroEnEstante('Libro', ['idioma' => 'Español', 'catalogado_por_staff_id' => $catalogador->id]);
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $ficha = collect($this->getJson("/api/libros/{$libro->id}")->assertOk()->json('ficha'))->keyBy('campo');

        $this->assertTrue($ficha['Idioma']['interno']);
        $this->assertSame('Carolina Muñoz', $ficha['Catalogador']['valor']);
        $this->assertFalse($ficha['Título']['interno']);
    }

    public function test_catalogar_guarda_los_campos_marc_el_orden_de_los_autores_y_quien_catalogo(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $respuesta = $this->postJson('/api/libros', [
            'codigo_barras' => '30000000009999',
            'titulo' => 'Flora de Magallanes',
            'titulo_alternativo' => 'Plantas de la Patagonia austral',
            'edicion' => '2a ed.',
            'autores' => ['Pisano, Edmundo', 'Domínguez, Erwin'],
            'idioma' => 'Español',
            'url_acceso' => 'https://ejemplo.cl/flora',
        ])->assertCreated();

        $libro = Libro::findOrFail($respuesta->json('id'));
        $this->assertSame(['Pisano, Edmundo', 'Domínguez, Erwin'], $libro->autores->pluck('nombre')->all());
        $this->assertSame($staff->id, $libro->catalogado_por_staff_id);
        $this->assertSame('2a ed.', $libro->edicion);
        $this->assertSame('Pisano, Edmundo', collect($respuesta->json('ficha'))->firstWhere('campo', 'Autor principal')['valor']);

        $this->postJson('/api/libros', [
            'codigo_barras' => '30000000009998',
            'titulo' => 'Con enlace inválido',
            'url_acceso' => 'no es una dirección',
        ])->assertStatus(422);
    }

    public function test_las_reservas_del_portal_no_exponen_datos_internos_del_libro(): void
    {
        $usuario = Usuario::factory()->create();
        $libro = Libro::factory()->create(['nota_interna' => 'Solo para el personal']);
        ReservaLibro::factory()->create(['usuario_id' => $usuario->id, 'libro_id' => $libro->id]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/mi/reservas-libro')
            ->assertOk()
            ->assertJsonPath('0.libro.titulo', $libro->titulo)
            ->assertJsonMissingPath('0.libro.nota_interna');
    }

    public function test_administracion_elige_la_plantilla_de_cada_tipo_de_material(): void
    {
        $tipo = TipoMaterial::where('nombre', 'DVD')->firstOrFail();

        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $this->patchJson("/api/tipos-material/{$tipo->id}", ['plantilla' => 'juego_educativo'])->assertForbidden();

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->patchJson("/api/tipos-material/{$tipo->id}", ['plantilla' => 'otra'])->assertStatus(422);
        $this->patchJson("/api/tipos-material/{$tipo->id}", ['plantilla' => 'juego_educativo'])
            ->assertOk()
            ->assertJsonPath('plantilla', 'juego_educativo');

        $plantillas = collect($this->getJson('/api/libros/plantillas')->assertOk()->json());
        $this->assertSame(['libro', 'trabajo_titulacion', 'juego_educativo', 'publicacion_seriada'], $plantillas->pluck('clave')->all());
    }
}
