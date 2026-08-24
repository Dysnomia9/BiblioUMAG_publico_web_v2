<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibroHistorialTest extends TestCase
{
    use RefreshDatabase;

    public function test_historial_busca_por_titulo_y_agrupa_por_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Cien Años de Soledad']);
        $copiaUno = Ejemplar::factory()->for($libro)->create(['numero_copia' => 1]);
        $copiaDos = Ejemplar::factory()->for($libro)->create(['numero_copia' => 2]);

        Prestamo::factory()->count(2)->create(['ejemplar_id' => $copiaUno->id, 'usuario_id' => Usuario::factory(), 'tipo_item' => 'libro']);
        Prestamo::factory()->create(['ejemplar_id' => $copiaDos->id, 'usuario_id' => Usuario::factory(), 'tipo_item' => 'libro']);

        $response = $this->getJson('/api/libros/historial?q=Soledad');

        $response->assertStatus(200)->assertJsonCount(1);
        $data = $response->json('0');
        $this->assertSame(3, $data['total_prestamos']);
        $this->assertSame(2, collect($data['ejemplares'])->firstWhere('id', $copiaUno->id)['total_prestamos']);
        $this->assertSame(1, collect($data['ejemplares'])->firstWhere('id', $copiaDos->id)['total_prestamos']);
    }

    public function test_historial_busca_por_codigo_de_barras_de_cualquier_copia(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Otro título']);
        $ejemplar = Ejemplar::factory()->for($libro)->create(['codigo_barras' => 'UMAG000055']);

        $response = $this->getJson('/api/libros/historial?q=UMAG000055');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('Otro título', $response->json('0.libro.titulo'));
    }

    public function test_historial_sin_busqueda_devuelve_todos_los_libros(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Ejemplar::factory()->for(Libro::factory())->create();
        Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->getJson('/api/libros/historial');

        $response->assertStatus(200)->assertJsonCount(2);
    }
}
