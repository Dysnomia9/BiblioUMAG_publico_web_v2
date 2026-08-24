<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestamoLibroTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestamo_de_libro_con_codigo_no_registrado_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => 'NO-EXISTE',
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(404);
    }

    public function test_prestamo_de_libro_ya_prestado_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(409);
    }

    public function test_prestamo_de_libro_que_no_esta_en_estante_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['estado_proceso' => 'procesos_tecnicos']);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(409);
    }

    public function test_prestamo_de_libro_disponible_lo_marca_como_no_disponible_y_guarda_ejemplar_id(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();
        $ejemplar = Ejemplar::factory()->for($libro)->create();
        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('ejemplar_id', $ejemplar->id)
            ->assertJsonPath('libro_titulo', $libro->titulo)
            ->assertJsonPath('codigo_barras', $ejemplar->codigo_barras);

        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
    }

    public function test_prestamo_de_una_segunda_copia_incluye_el_numero_de_copia_en_el_titulo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Título con copias']);
        $copiaUno = Ejemplar::factory()->for($libro)->create(['numero_copia' => 1]);
        $copiaDos = Ejemplar::factory()->for($libro)->create(['numero_copia' => 2]);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $copiaDos->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('libro_titulo', 'Título con copias (Copia 2)');
        // La primera copia no se ve afectada por el préstamo de la segunda.
        $this->assertDatabaseHas('ejemplares', ['id' => $copiaUno->id, 'disponible' => true]);
    }

    public function test_prestamo_de_libro_sin_fecha_devolucion_devuelve_422(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    public function test_usuario_con_libro_activo_sin_devolver_no_puede_llevarse_otro(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $yaPrestado = Ejemplar::factory()->for(Libro::factory())->create();
        $nuevoLibro = Ejemplar::factory()->for(Libro::factory())->create();

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $yaPrestado->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $nuevoLibro->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario ya tiene un libro prestado sin devolver — debe devolverlo antes de llevarse otro.');
        // El segundo ejemplar no debe quedar marcado como prestado tras el rechazo.
        $this->assertDatabaseHas('ejemplares', ['id' => $nuevoLibro->id, 'disponible' => true]);
    }

    public function test_usuario_puede_llevarse_un_libro_nuevo_despues_de_devolver_el_anterior(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $primero = Ejemplar::factory()->for(Libro::factory())->create();
        $segundo = Ejemplar::factory()->for(Libro::factory())->create();

        $prestamo = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $primero->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ])->json();

        $this->patchJson("/api/prestamos/{$prestamo['id']}/devolver")->assertStatus(200);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $segundo->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(201);
    }

    public function test_libro_activo_no_bloquea_prestamo_de_equipo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $libro = Ejemplar::factory()->for(Libro::factory())->create();
        $equipo = \App\Models\Equipo::factory()->create(['tipo' => 'audifonos']);

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $libro->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => now()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $response = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'audifonos',
            'codigo_barras' => $equipo->codigo_barras,
        ]);

        $response->assertStatus(201);
    }
}
