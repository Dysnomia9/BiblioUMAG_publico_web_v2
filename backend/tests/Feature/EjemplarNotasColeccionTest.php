<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Notas (pública e interna) y colección de cada copia, que vienen de Horizon por ítem. */
class EjemplarNotasColeccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_al_agregar_una_copia_se_guardan_sus_notas_y_su_coleccion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['coleccion' => 'General']);

        $this->postJson('/api/ejemplares', [
            'libro_id' => $libro->id,
            'codigo_barras' => '30000000000001',
            'coleccion' => 'Referencia',
            'nota_publica' => 'Incluye CD con ejercicios',
            'nota_interna' => 'Lomo reparado en 2019',
        ])->assertCreated()
            ->assertJsonPath('coleccion', 'Referencia')
            ->assertJsonPath('nota_publica', 'Incluye CD con ejercicios')
            ->assertJsonPath('nota_interna', 'Lomo reparado en 2019');
    }

    public function test_al_catalogar_un_libro_nuevo_se_guardan_las_notas_de_la_primera_copia_aparte_de_las_del_libro(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/libros', [
            'codigo_barras' => '30000000000002',
            'titulo' => 'Cálculo diferencial',
            'nota_publica' => 'Nota del libro',
            'nota_interna' => 'Interna del libro',
            'copia_nota_publica' => 'Incluye CD',
            'copia_nota_interna' => 'Donada en 2024',
        ])->assertCreated();

        $libro = Libro::where('titulo', 'Cálculo diferencial')->firstOrFail();
        $this->assertSame('Nota del libro', $libro->nota_publica);
        $this->assertSame('Interna del libro', $libro->nota_interna);
        $this->assertDatabaseHas('ejemplares', [
            'libro_id' => $libro->id,
            'numero_copia' => 1,
            'nota_publica' => 'Incluye CD',
            'nota_interna' => 'Donada en 2024',
        ]);
    }

    public function test_se_editan_las_notas_y_la_coleccion_de_una_copia_y_queda_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->create(['coleccion' => 'Referencia']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}", [
            'nota_publica' => 'Falta la página 12',
            'nota_interna' => 'Revisar en inventario',
            'coleccion' => '   ',
        ])->assertOk()
            ->assertJsonPath('nota_publica', 'Falta la página 12')
            ->assertJsonPath('coleccion', null);

        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'nota_interna' => 'Revisar en inventario', 'coleccion' => null]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.editar_copia', 'entidad_id' => $ejemplar->id]);
    }

    public function test_editar_una_copia_exige_el_permiso_de_catalogar(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $ejemplar = Ejemplar::factory()->create();

        $this->patchJson("/api/ejemplares/{$ejemplar->id}", ['nota_publica' => 'x'])->assertForbidden();
    }

    public function test_el_portal_muestra_la_coleccion_de_la_copia_o_la_del_libro_y_solo_la_nota_publica(): void
    {
        $libro = Libro::factory()->create(['coleccion' => 'General']);
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 1, 'coleccion' => 'Reserva', 'nota_publica' => 'Con mapa desplegable', 'nota_interna' => 'Secreto']);
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 2]);

        Sanctum::actingAs(Usuario::factory()->create());

        $respuesta = $this->getJson('/api/mi/catalogo')->assertOk()
            ->assertJsonPath('data.0.copias.0.coleccion', 'Reserva')
            ->assertJsonPath('data.0.copias.0.nota_publica', 'Con mapa desplegable')
            ->assertJsonPath('data.0.copias.1.coleccion', 'General');

        $this->assertStringNotContainsString('Secreto', $respuesta->getContent());
    }
}
