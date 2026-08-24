<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnumCheckConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuarios_tipo_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Usuario::factory()->create(['tipo' => 'invalido']);
    }

    public function test_prestamos_estado_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Prestamo::factory()->create(['estado' => 'invalido']);
    }

    public function test_prestamos_tipo_item_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Prestamo::factory()->create(['tipo_item' => 'invalido']);
    }

    public function test_prestamos_multa_estado_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Prestamo::factory()->create(['multa_estado' => 'invalido']);
    }

    public function test_prestamos_multa_estado_nulo_es_valido(): void
    {
        $prestamo = Prestamo::factory()->create(['multa_estado' => null]);
        $this->assertNull($prestamo->multa_estado);
    }

    public function test_ejemplares_estado_proceso_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'invalido']);
    }

    public function test_ejemplares_estado_proceso_coleccion_movil_y_personalizado_son_validos(): void
    {
        $libro = Libro::factory()->create();
        $movil = Ejemplar::factory()->for($libro)->create(['estado_proceso' => 'coleccion_movil']);
        $personalizado = Ejemplar::factory()->for($libro)->create(['estado_proceso' => 'personalizado']);

        $this->assertSame('coleccion_movil', $movil->estado_proceso);
        $this->assertSame('personalizado', $personalizado->estado_proceso);
    }

    public function test_reservas_libro_estado_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        ReservaLibro::factory()->create(['estado' => 'invalido']);
    }

    public function test_reservas_estado_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Reserva::factory()->create(['estado' => 'invalido']);
    }

    public function test_reservas_estado_cancelada_es_valido(): void
    {
        $reserva = Reserva::factory()->create(['estado' => 'cancelada']);
        $this->assertSame('cancelada', $reserva->estado);
    }

    public function test_equipos_tipo_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Equipo::factory()->create(['tipo' => 'invalido']);
    }

    public function test_usuarios_sexo_libre_sigue_sin_restriccion(): void
    {
        $usuario = Usuario::factory()->create(['sexo' => 'Otro']);
        $this->assertSame('Otro', $usuario->sexo);
    }
}
