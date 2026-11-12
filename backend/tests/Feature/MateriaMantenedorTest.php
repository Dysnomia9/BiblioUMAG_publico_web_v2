<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MateriaMantenedorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
    }

    public function test_renombrar_una_materia_la_mantiene_en_sus_libros(): void
    {
        $this->admin();
        $materia = Categoria::create(['nombre' => 'TESIS ELECTRONICA']);
        $libros = Libro::factory()->count(3)->create();
        $materia->libros()->attach($libros->pluck('id'));

        $this->patchJson("/api/materias/{$materia->id}", ['nombre' => 'Tesis  electrónica'])
            ->assertOk()
            ->assertJsonPath('nombre', 'Tesis electrónica')
            ->assertJsonPath('libros_count', 3);

        foreach ($libros as $libro) {
            $this->assertSame(['Tesis electrónica'], $libro->fresh()->categorias->pluck('nombre')->all());
        }
    }

    public function test_renombrar_a_una_que_ya_existe_pide_unir_y_luego_une(): void
    {
        $this->admin();
        $vieja = Categoria::create(['nombre' => 'COMUNICACION']);
        $buena = Categoria::create(['nombre' => 'COMUNICACIÓN']);
        [$a, $b] = Libro::factory()->count(2)->create();
        $vieja->libros()->attach([$a->id, $b->id]);
        $buena->libros()->attach([$b->id]);

        $this->patchJson("/api/materias/{$vieja->id}", ['nombre' => 'comunicación'])
            ->assertStatus(409)
            ->assertJsonPath('existente.id', $buena->id);
        $this->assertDatabaseHas('categorias', ['id' => $vieja->id]);

        $this->patchJson("/api/materias/{$vieja->id}", ['nombre' => 'comunicación', 'unir' => true])
            ->assertOk()
            ->assertJsonPath('unida_con.libros_count', 2);
        $this->assertDatabaseMissing('categorias', ['id' => $vieja->id]);
        $this->assertSame(1, $b->fresh()->categorias()->count());
    }

    public function test_solo_se_elimina_una_materia_sin_libros(): void
    {
        $this->admin();
        $usada = Categoria::create(['nombre' => 'USADA']);
        $usada->libros()->attach(Libro::factory()->create()->id);
        $libre = Categoria::create(['nombre' => 'SIN USO']);

        $this->deleteJson("/api/materias/{$usada->id}")->assertStatus(409);
        $this->deleteJson("/api/materias/{$libre->id}")->assertNoContent();
        $this->assertDatabaseMissing('categorias', ['id' => $libre->id]);
    }

    public function test_listado_busca_sin_tildes_y_cuenta_libros(): void
    {
        $this->admin();
        $materia = Categoria::create(['nombre' => 'TESIS ELECTRÓNICA']);
        $materia->libros()->attach(Libro::factory()->count(2)->create()->pluck('id'));
        Categoria::create(['nombre' => 'OTRA']);

        $this->getJson('/api/materias?q=tesis electronica')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.libros_count', 2);
    }

    public function test_sin_permiso_no_se_gestionan_materias(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'bibliotecario']));
        $materia = Categoria::create(['nombre' => 'X']);

        $this->patchJson("/api/materias/{$materia->id}", ['nombre' => 'Y'])->assertForbidden();
    }
}
