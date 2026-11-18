<?php

namespace Tests\Feature;

use App\Models\CategoriaRecurso;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CodigoCortoEquipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
        CategoriaRecurso::firstOrCreate(['clave' => 'mouse'], ['nombre' => 'Mouse', 'nombre_plural' => 'Mouse', 'horas_maximas' => 3]);
    }

    public function test_busca_el_mismo_recurso_con_codigo_corto_o_completo(): void
    {
        foreach (['notebook' => '03226834', 'cargador' => '03226889', 'mouse' => '03222720', 'bicicleta' => '03234396', 'casco' => '03234242', 'chaleco' => '03234167'] as $tipo => $corto) {
            $completo = '300000'.$corto;
            $equipo = Equipo::factory()->create(['tipo' => $tipo, 'codigo_barras' => $completo]);
            foreach ([$corto, $completo] as $codigo) {
                $this->getJson('/api/equipos/'.$codigo)->assertOk()
                    ->assertJsonPath('id', $equipo->id)->assertJsonPath('codigo_barras', $completo)
                    ->assertJsonPath('categoria.clave', $tipo);
            }
        }
    }

    public function test_presta_y_devuelve_con_codigo_corto_guardando_el_codigo_original(): void
    {
        $equipo = Equipo::factory()->create(['tipo' => 'mouse', 'codigo_barras' => '30000003222720']);
        $datos = ['usuario_id' => Usuario::factory()->create()->id, 'tipo_item' => 'mouse', 'codigo_barras' => '03222720'];
        $r = $this->postJson('/api/prestamos', $datos)->assertCreated()
            ->assertJsonPath('equipo_id', $equipo->id)->assertJsonPath('codigo_barras', '30000003222720');
        $id = $r->json('id');
        $this->postJson('/api/prestamos', [...$datos, 'codigo_barras' => '30000003222720'])->assertConflict();
        $this->assertSame(1, Prestamo::count());
        $this->getJson('/api/prestamos/por-codigo?codigo=03222720')->assertOk()->assertJsonPath('id', $id);
        $this->patchJson("/api/prestamos/{$id}/devolver", ['solo_sin_multa' => true])->assertOk()->assertJsonPath('estado', 'devuelto');
        $this->assertTrue($equipo->fresh()->disponible);
    }

    public function test_conserva_los_codigos_cortos_guardados_y_no_confunde_otras_categorias(): void
    {
        $bici = Equipo::factory()->create(['tipo' => 'bicicleta', 'codigo_barras' => '03234396']);
        $this->getJson('/api/equipos/03234396')->assertOk()->assertJsonPath('id', $bici->id);
        $mouse = Equipo::factory()->create(['tipo' => 'mouse', 'codigo_barras' => '30000003222720']);
        $this->postJson('/api/prestamos', ['usuario_id' => Usuario::factory()->create()->id, 'tipo_item' => 'notebook', 'codigo_barras' => '03222720'])
            ->assertUnprocessable()->assertJsonPath('message', 'Ese código es de Mouse ('.$mouse->codigo_inventario.'), no de Notebook.');
        $this->getJson('/api/equipos/03222721')->assertNotFound();
    }
}
