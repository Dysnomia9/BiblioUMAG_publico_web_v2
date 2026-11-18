<?php

namespace Tests\Feature;

use App\Models\CategoriaRecurso;
use App\Models\Prestamo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReporteRecursosActualesTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        CategoriaRecurso::create(['clave' => 'mouse', 'nombre' => 'Mouse', 'nombre_plural' => 'Mouse', 'activo' => true]);
        foreach (['notebook' => 'PC 824', 'bicicleta' => 'Bicicleta 1', 'cargador' => 'Cargador PC 824', 'mouse' => 'Mouse 1', 'libro' => 'Libro de prueba'] as $tipo => $nombre) {
            Prestamo::factory()->create(['tipo_item' => $tipo, 'libro_titulo' => $nombre, 'fecha_prestamo' => '2025-05-12 10:00:00']);
        }
        Prestamo::factory()->create(['tipo_item' => 'notebook', 'libro_titulo' => 'PC 824', 'fecha_prestamo' => '2025-05-14 10:00:00', 'estado' => 'devuelto', 'fecha_devolucion_real' => '2025-05-14 12:00:00']);
        Prestamo::factory()->create(['tipo_item' => 'notebook', 'libro_titulo' => 'PC 825', 'fecha_prestamo' => '2025-04-12 10:00:00']);
        $base = ['fecha' => '2025-05-12', 'hora_inicio' => '10:00', 'hora_fin' => '11:00'];
        DB::table('usos_horizon')->insert([['servicio' => 'logia', ...$base], ['servicio' => 'puesto', ...$base]]);
    }

    public function test_material_mas_prestado_incluye_pcs_bicicletas_y_categorias_nuevas(): void
    {
        $this->preparar();
        foreach (['prestamos', 'libros'] as $tab) {
            $r = $this->getJson("/api/reportes/resumen?tab={$tab}&periodo=mes&fecha=2025-05-12")
                ->assertOk()->assertJsonPath('total', 6)->assertJsonPath('totalAnterior', 1)
                ->assertJsonPath('usosHorizon', 0)
                ->assertJsonPath('porLibro', [['label' => 'Libro de prueba', 'value' => 1]]);
            $this->assertSame(['PC 824' => 2, 'Bicicleta 1' => 1, 'Cargador PC 824' => 1, 'Mouse 1' => 1], collect($r->json('porEquipo'))->pluck('value', 'label')->all());
            $this->assertSame(2, collect($r->json('porRecurso'))->firstWhere('label', 'notebook')['value']);
            $this->assertSame(1, collect($r->json('porRecurso'))->firstWhere('label', 'bicicleta')['value']);
            $this->assertSame(1, collect($r->json('porRecurso'))->firstWhere('label', 'mouse')['value']);
            foreach (['serie', 'porCarrera', 'porSexo', 'porTipoUsuario'] as $campo) {
                $this->assertSame(6, collect($r->json($campo))->sum('value'), $campo);
            }
        }
    }

    public function test_filtra_categoria_sin_mezclar_libros_ni_usos_de_horizon(): void
    {
        $this->preparar();
        CategoriaRecurso::where('clave', 'mouse')->update(['activo' => false]);
        foreach (['prestamos', 'libros'] as $tab) {
            $url = "/api/reportes/resumen?tab={$tab}&periodo=mes&fecha=2025-05-12&tipo_prestamo=recursos_apoyo";
            $this->getJson($url)->assertOk()->assertJsonPath('total', 5)->assertJsonPath('porLibro', [])->assertJsonPath('usosHorizon', 0);
            $this->getJson($url.'&recurso=notebook')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('porEquipo', [['label' => 'PC 824', 'value' => 2]]);
            $this->getJson($url.'&recurso=mouse')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('porEquipo', [['label' => 'Mouse 1', 'value' => 1]]);
            $this->getJson("/api/reportes/resumen?tab={$tab}&periodo=mes&fecha=2025-05-12&tipo_prestamo=bibliografico")
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('porEquipo', []);
        }
        $this->getJson('/api/reportes/opciones')->assertOk()->assertJsonPath('recursos.mouse', 'Mouse');
        $this->getJson('/api/reportes/resumen?tab=logias&periodo=mes&fecha=2025-05-12')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/reportes/resumen?tab=ingresos&periodo=mes&fecha=2025-05-12')->assertOk()->assertJsonPath('total', 1);
    }
}
