<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Entrada;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CascadaRestrictTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_puede_borrar_un_usuario_con_prestamos(): void
    {
        $usuario = Usuario::factory()->create();
        Prestamo::factory()->create(['usuario_id' => $usuario->id]);

        $this->expectException(QueryException::class);
        $usuario->delete();
    }

    public function test_no_se_puede_borrar_un_usuario_con_entradas(): void
    {
        $usuario = Usuario::factory()->create();
        Entrada::factory()->create(['usuario_id' => $usuario->id]);

        $this->expectException(QueryException::class);
        $usuario->delete();
    }

    public function test_no_se_puede_borrar_un_usuario_con_reservas_de_libro(): void
    {
        $usuario = Usuario::factory()->create();
        ReservaLibro::factory()->create(['usuario_id' => $usuario->id]);

        $this->expectException(QueryException::class);
        $usuario->delete();
    }

    public function test_no_se_puede_borrar_un_ejemplar_con_prestamos(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'tipo_item' => 'libro']);

        $this->expectException(QueryException::class);
        $ejemplar->delete();
    }

    public function test_no_se_puede_borrar_un_ejemplar_con_reservas(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        ReservaLibro::factory()->create(['libro_id' => $ejemplar->libro_id, 'ejemplar_id' => $ejemplar->id]);

        $this->expectException(QueryException::class);
        $ejemplar->delete();
    }

    public function test_no_se_puede_borrar_un_libro_con_reservas(): void
    {
        $libro = Libro::factory()->create();
        ReservaLibro::factory()->create(['libro_id' => $libro->id]);

        $this->expectException(QueryException::class);
        $libro->delete();
    }

    public function test_no_se_puede_borrar_un_libro_con_ejemplares(): void
    {
        $libro = Libro::factory()->create();
        Ejemplar::factory()->for($libro)->create();

        $this->expectException(QueryException::class);
        $libro->delete();
    }

    public function test_no_se_puede_borrar_un_equipo_con_prestamos(): void
    {
        $equipo = Equipo::factory()->create();
        Prestamo::factory()->create(['equipo_id' => $equipo->id, 'tipo_item' => $equipo->tipo]);

        $this->expectException(QueryException::class);
        $equipo->delete();
    }

    public function test_se_puede_borrar_un_usuario_sin_historial_asociado(): void
    {
        $usuario = Usuario::factory()->create();

        $usuario->delete();

        $this->assertDatabaseMissing('usuarios', ['id' => $usuario->id]);
    }
}
