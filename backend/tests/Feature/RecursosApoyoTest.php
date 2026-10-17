<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\CategoriaRecurso;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Recursos de apoyo: categorías administrables (bicicletas y lo que venga) y su préstamo. */
class RecursosApoyoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vienen_las_categorias_de_siempre_y_la_de_bicicletas(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $claves = collect($this->getJson('/api/categorias-recurso')->assertOk()->json())->pluck('clave')->sort()->values()->all();

        $this->assertSame(['audifonos', 'bicicleta', 'cargador', 'notebook'], $claves);
        $this->assertSame(1, CategoriaRecurso::where('clave', 'bicicleta')->value('limite_por_persona'));
    }

    public function test_administracion_crea_una_categoria_nueva_y_queda_en_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/categorias-recurso', [
            'nombre' => 'Calculadora científica',
            'nombre_plural' => 'Calculadoras científicas',
            'icono' => 'calculadora',
            'color' => 'amber',
            'limite_por_persona' => 2,
            'indicaciones' => 'Solo para uso en sala',
        ])->assertCreated()
            ->assertJsonPath('clave', 'calculadora_cientifica')
            ->assertJsonPath('activo', true);

        $this->assertDatabaseHas('bitacora', ['accion' => 'configuracion.crear_categoria_recurso']);
    }

    public function test_sin_permiso_de_catalogos_no_se_crean_categorias(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->postJson('/api/categorias-recurso', ['nombre' => 'Tablet', 'nombre_plural' => 'Tablets', 'icono' => 'tablet', 'color' => 'sky'])
            ->assertForbidden();
    }

    public function test_cambiar_el_nombre_no_cambia_la_clave_y_se_puede_desactivar(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $bicicleta = CategoriaRecurso::where('clave', 'bicicleta')->sole();

        $this->patchJson("/api/categorias-recurso/{$bicicleta->id}", ['nombre' => 'Bicicleta urbana', 'nombre_plural' => 'Bicicletas urbanas'])
            ->assertOk()
            ->assertJsonPath('clave', 'bicicleta');
        $this->patchJson("/api/categorias-recurso/{$bicicleta->id}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);

        $this->assertDatabaseHas('bitacora', ['accion' => 'configuracion.desactivar_categoria_recurso']);
    }

    public function test_se_presta_una_bicicleta_y_solo_una_por_persona(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $primera = Equipo::factory()->create(['tipo' => 'bicicleta', 'codigo_inventario' => 'Bicicleta 01', 'codigo_barras' => 'BICI-01']);
        Equipo::factory()->create(['tipo' => 'bicicleta', 'codigo_inventario' => 'Bicicleta 02', 'codigo_barras' => 'BICI-02']);

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'bicicleta', 'codigo_barras' => 'BICI-01'])
            ->assertCreated()
            ->assertJsonPath('libro_titulo', 'Bicicleta 01')
            ->assertJsonMissingPath('categoria_recurso_clave');
        $this->assertFalse($primera->fresh()->disponible);

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'bicicleta', 'codigo_barras' => 'BICI-02'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario ya tiene 1 bicicleta sin devolver: se presta un máximo de 1 por persona a la vez.');

        // Al devolverla, puede llevarse otra.
        $this->patchJson('/api/prestamos/'.Prestamo::sole()->id.'/devolver')->assertOk();
        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'bicicleta', 'codigo_barras' => 'BICI-02'])->assertCreated();
    }

    public function test_un_recurso_de_categoria_nueva_se_registra_y_se_presta(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $usuario = Usuario::factory()->create();
        CategoriaRecurso::factory()->create(['clave' => 'tablet', 'nombre' => 'Tablet', 'nombre_plural' => 'Tablets']);

        $this->postJson('/api/equipos', ['codigo_inventario' => 'Tablet 01', 'codigo_barras' => 'TAB-01', 'tipo' => 'tablet'])->assertCreated();
        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'tablet', 'codigo_barras' => 'TAB-01'])->assertCreated();

        $this->assertDatabaseHas('prestamos', ['tipo_item' => 'tablet', 'categoria_recurso_clave' => 'tablet']);
    }

    public function test_una_categoria_desactivada_no_admite_recursos_ni_prestamos_nuevos(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $usuario = Usuario::factory()->create();
        Equipo::factory()->create(['tipo' => 'cargador', 'codigo_barras' => 'CAR-09']);
        CategoriaRecurso::where('clave', 'cargador')->update(['activo' => false]);

        $this->postJson('/api/equipos', ['codigo_inventario' => 'Cargador 09', 'codigo_barras' => 'CAR-10', 'tipo' => 'cargador'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'cargador', 'codigo_barras' => 'CAR-09'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_item');
    }

    public function test_escanear_el_codigo_de_otra_categoria_lo_explica(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        Equipo::factory()->create(['tipo' => 'notebook', 'codigo_inventario' => 'Notebook 07', 'codigo_barras' => 'NB-07']);

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'bicicleta', 'codigo_barras' => 'NB-07'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ese código es de Notebook (Notebook 07), no de Bicicleta.');
    }

    public function test_la_base_rechaza_un_recurso_o_prestamo_de_una_categoria_que_no_existe(): void
    {
        $this->expectException(QueryException::class);
        Equipo::factory()->create(['tipo' => 'patineta']);
    }

    public function test_el_reporte_nombra_los_recursos_con_el_catalogo(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->getJson('/api/reportes/opciones')->assertOk()
            ->assertJsonPath('recursos.libro', 'Libros')
            ->assertJsonPath('recursos.bicicleta', 'Bicicletas');
    }

    public function test_registrar_un_recurso_queda_en_la_bitacora_con_su_categoria(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/equipos', ['codigo_inventario' => 'Bicicleta 09', 'codigo_barras' => 'BICI-09', 'tipo' => 'bicicleta'])->assertCreated();

        $this->assertSame('Agregó el recurso Bicicleta 09 (Bicicleta, código BICI-09)', Bitacora::sole()->descripcion);
    }
}
