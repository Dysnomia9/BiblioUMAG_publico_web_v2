<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Multa;
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

    public function test_prestamo_de_libro_con_un_recurso_falla_a_nivel_de_base_de_datos(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $equipo = Equipo::factory()->create(['tipo' => 'audifonos']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_prestamos_libro_o_recurso');
        Prestamo::factory()->create(['tipo_item' => 'libro', 'ejemplar_id' => $ejemplar->id, 'equipo_id' => $equipo->id]);
    }

    public function test_prestamo_de_recurso_con_una_copia_falla_a_nivel_de_base_de_datos(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_prestamos_libro_o_recurso');
        Prestamo::factory()->create(['tipo_item' => 'audifonos', 'ejemplar_id' => $ejemplar->id]);
    }

    public function test_prestamos_que_apuntan_a_lo_de_su_tipo_o_a_nada_son_validos(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $equipo = Equipo::factory()->create(['tipo' => 'audifonos']);

        Prestamo::factory()->create(['tipo_item' => 'libro', 'ejemplar_id' => $ejemplar->id]);
        Prestamo::factory()->create(['tipo_item' => 'audifonos', 'equipo_id' => $equipo->id]);
        // Historial de antes de los ejemplares y los equipos: sin copia ni recurso.
        Prestamo::factory()->create(['tipo_item' => 'libro']);
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $this->assertSame(4, Prestamo::count());
    }

    public function test_multas_estado_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Multa::factory()->create(['estado' => 'invalido']);
    }

    public function test_multas_tipo_invalido_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Multa::factory()->create(['tipo' => 'invalido']);
    }

    public function test_multa_de_un_prestamo_y_de_una_reserva_a_la_vez_falla_a_nivel_de_base_de_datos(): void
    {
        $prestamo = Prestamo::factory()->create();
        $reserva = Reserva::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_multas_prestamo_o_reserva');
        Multa::factory()->create(['prestamo_id' => $prestamo->id, 'reserva_id' => $reserva->id]);
    }

    public function test_multas_monto_cero_falla_a_nivel_de_base_de_datos(): void
    {
        $this->expectException(QueryException::class);
        Multa::factory()->create(['monto' => 0]);
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
