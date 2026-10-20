<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Las búsquedas por título o nombre no distinguen tildes, ñ ni mayúsculas. */
class BusquedaSinTildesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
    }

    public function test_el_titulo_se_encuentra_con_o_sin_tildes(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Cálculo diferencial']);
        Libro::factory()->create(['titulo' => 'Química general']);

        foreach (['calculo', 'CALCULO', 'Cálculo', 'cálculo dif'] as $busqueda) {
            $ids = collect($this->getJson('/api/libros?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "buscando «{$busqueda}»");
        }
    }

    public function test_el_autor_se_encuentra_sin_tildes_ni_enie(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Historia de Magallanes']);
        $libro->autores()->attach(Autor::factory()->create(['nombre' => 'Muñoz Pérez, José']));

        $ids = collect($this->getJson('/api/libros?q=munoz perez')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$libro->id], $ids);
    }

    public function test_las_palabras_se_encuentran_en_cualquier_orden_y_campo(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Cálculo Diferencial e Integral']);
        $libro->autores()->attach(Autor::factory()->create(['nombre' => 'Stewart, James']));
        Libro::factory()->create(['titulo' => 'Cálculo vectorial']);

        foreach (['calculo stewart', 'integral calculo', 'calc integ', 'CALCULO   diferencial  STEWART'] as $busqueda) {
            $ids = collect($this->getJson('/api/libros?q='.urlencode($busqueda))->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$libro->id], $ids, "buscando «{$busqueda}»");
        }

        $this->assertSame([], $this->getJson('/api/libros?q='.urlencode('calculo python'))->assertOk()->json('data'));
    }

    public function test_las_personas_se_encuentran_sin_tildes(): void
    {
        $usuario = Usuario::factory()->create(['nombre' => 'Sebastián', 'apellido' => 'Núñez']);
        Usuario::factory()->create(['nombre' => 'Pedro', 'apellido' => 'Soto']);

        $ids = collect($this->getJson('/api/usuarios?q=nunez')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$usuario->id], $ids);

        $ids = collect($this->getJson('/api/usuarios?q=sebastian')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$usuario->id], $ids);
    }
}
