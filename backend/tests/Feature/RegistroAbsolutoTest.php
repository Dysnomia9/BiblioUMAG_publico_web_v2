<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistroAbsolutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_no_admin_no_puede_ver_el_registro_absoluto(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'staff']));

        $response = $this->getJson('/api/registro-absoluto');

        $response->assertStatus(403);
    }

    public function test_admin_ve_operaciones_de_los_cuatro_tipos_juntas(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        Prestamo::factory()->create(['fecha_prestamo' => now()->subDays(2)]);
        Reserva::factory()->create(['fecha' => now()->toDateString()]);
        ReservaLibro::factory()->create(['fecha_reserva' => now()->toDateString()]);
        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDay()]);

        $response = $this->getJson('/api/registro-absoluto');

        $response->assertStatus(200);
        $tipos = collect($response->json('operaciones'))->pluck('tipo')->unique()->sort()->values();
        $this->assertEquals(['entrada', 'prestamo_libro', 'reserva_libro', 'reserva_sala'], $tipos->all());
        $this->assertSame(4, $response->json('total'));
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        Prestamo::factory()->create(['fecha_prestamo' => now()->subDays(60), 'libro_titulo' => 'Fuera de rango']);
        Prestamo::factory()->create(['fecha_prestamo' => now()->subDays(1), 'libro_titulo' => 'Dentro de rango']);

        $response = $this->getJson('/api/registro-absoluto?desde='.now()->subDays(7)->toDateString());

        $response->assertStatus(200);
        $detalles = collect($response->json('operaciones'))->pluck('detalle');
        $this->assertTrue($detalles->contains('Dentro de rango'));
        $this->assertFalse($detalles->contains('Fuera de rango'));
    }

    public function test_filtra_por_tipo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        Prestamo::factory()->create(['fecha_prestamo' => now()]);
        Reserva::factory()->create(['fecha' => now()->toDateString()]);

        $response = $this->getJson('/api/registro-absoluto?tipo[]=prestamo');

        $response->assertStatus(200);
        $tipos = collect($response->json('operaciones'))->pluck('tipo')->unique();
        $this->assertTrue($tipos->every(fn ($t) => str_starts_with($t, 'prestamo')));
    }

    public function test_busqueda_filtra_por_nombre_o_rut_del_usuario(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $usuario = Usuario::factory()->create(['nombre' => 'Fernanda', 'apellido' => 'Ríos', 'rut' => '11.111.111-1']);
        $otro = Usuario::factory()->create(['nombre' => 'Pedro', 'apellido' => 'Soto', 'rut' => '22.222.222-2']);

        Prestamo::factory()->create(['usuario_id' => $usuario->id, 'fecha_prestamo' => now(), 'libro_titulo' => 'Libro de Fernanda']);
        Prestamo::factory()->create(['usuario_id' => $otro->id, 'fecha_prestamo' => now(), 'libro_titulo' => 'Libro de Pedro']);

        $response = $this->getJson('/api/registro-absoluto?q=Fernanda');

        $response->assertStatus(200);
        $detalles = collect($response->json('operaciones'))->pluck('detalle');
        $this->assertTrue($detalles->contains('Libro de Fernanda'));
        $this->assertFalse($detalles->contains('Libro de Pedro'));
    }

    public function test_prestamo_expone_el_staff_que_lo_atendio(): void
    {
        $admin = Staff::factory()->create(['rol' => 'admin', 'nombre' => 'Ignacio Contreras']);
        Sanctum::actingAs($admin);

        Prestamo::factory()->create([
            'fecha_prestamo' => now(),
            'prestado_por_staff_id' => $admin->id,
        ]);

        $response = $this->getJson('/api/registro-absoluto');

        $response->assertStatus(200);
        $this->assertSame('Ignacio Contreras', $response->json('operaciones.0.atendido_por'));
    }

    public function test_reserva_libro_sin_staff_asociado_no_expone_atendido_por(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        ReservaLibro::factory()->create(['fecha_reserva' => now()->toDateString(), 'registrado_por_staff_id' => null]);

        $response = $this->getJson('/api/registro-absoluto?tipo[]=reserva_libro');

        $response->assertStatus(200);
        $this->assertNull($response->json('operaciones.0.atendido_por'));
    }

    public function test_reserva_libro_creada_por_staff_expone_quien_la_atendio(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $staff = Staff::factory()->create(['nombre' => 'Fernanda Ríos']);
        ReservaLibro::factory()->create(['fecha_reserva' => now()->toDateString(), 'registrado_por_staff_id' => $staff->id]);

        $response = $this->getJson('/api/registro-absoluto?tipo[]=reserva_libro');

        $response->assertStatus(200);
        $this->assertSame('Fernanda Ríos', $response->json('operaciones.0.atendido_por'));
    }

    public function test_ordena_operaciones_de_mas_reciente_a_mas_antigua(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        Prestamo::factory()->create(['fecha_prestamo' => now()->subDays(5), 'libro_titulo' => 'Antiguo']);
        Prestamo::factory()->create(['fecha_prestamo' => now(), 'libro_titulo' => 'Reciente']);

        $response = $this->getJson('/api/registro-absoluto');

        $response->assertStatus(200);
        $detalles = collect($response->json('operaciones'))->pluck('detalle')->all();
        $this->assertSame(['Reciente', 'Antiguo'], $detalles);
    }
}
