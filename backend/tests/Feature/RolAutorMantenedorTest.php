<?php

namespace Tests\Feature;

use App\Models\Libro;
use App\Models\RolAutor;
use App\Models\Staff;
use App\Support\FichaBibliografica;
use App\Support\RolesAutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolAutorMantenedorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAutor::olvidar();
    }

    public function test_vienen_los_papeles_de_tesis_y_se_usan_en_la_ficha(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $claves = collect($this->getJson('/api/roles-autor')->assertOk()->json())->pluck('clave');
        $this->assertTrue($claves->contains('profesor_patrocinante'));
        $this->assertTrue($claves->contains('asesor_metodologico'));

        $this->postJson('/api/libros', [
            'titulo' => 'Tesis',
            'autores' => ['Pérez, Ana', 'Soto, Juan'],
            'autores_roles' => [null, 'profesor_patrocinante'],
        ])->assertStatus(201);

        $fila = collect(FichaBibliografica::ficha(Libro::with('autores', 'categorias', 'carreras', 'tipoMaterial')->first(), true))->firstWhere('etiqueta', '700');
        $this->assertSame('Profesores patrocinantes', $fila['campo']);
    }

    public function test_crear_editar_desactivar_y_eliminar_un_papel(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $id = $this->postJson('/api/roles-autor', ['nombre' => 'Corrector de estilo', 'abreviatura' => 'corr.', 'plural' => 'Correctores de estilo'])
            ->assertStatus(201)->assertJsonPath('clave', 'corrector_de_estilo')->json('id');

        $this->patchJson("/api/roles-autor/{$id}", ['plural' => 'Correctores'])->assertOk()->assertJsonPath('plural', 'Correctores');
        $this->patchJson("/api/roles-autor/{$id}", ['activo' => false])->assertOk();
        $this->assertFalse(collect($this->getJson('/api/roles-autor')->json())->pluck('id')->contains($id));
        $this->assertTrue(collect($this->getJson('/api/roles-autor?todos=1')->json())->pluck('id')->contains($id));

        $this->deleteJson("/api/roles-autor/{$id}")->assertNoContent();
    }

    public function test_un_papel_en_uso_no_se_elimina(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->postJson('/api/libros', ['titulo' => 'X', 'autores' => ['A, B'], 'autores_roles' => ['traductor']])->assertStatus(201);
        $traductor = RolAutor::where('clave', 'traductor')->first();

        $this->deleteJson("/api/roles-autor/{$traductor->id}")->assertStatus(409);
    }

    public function test_sin_permiso_no_se_gestionan(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'bibliotecario']));
        $this->postJson('/api/roles-autor', ['nombre' => 'X', 'abreviatura' => 'x', 'plural' => 'X'])->assertForbidden();
    }
}
