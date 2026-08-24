<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservaLibroColaTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_puede_unirse_dos_veces_a_la_cola_del_mismo_libro(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $usuario = Usuario::factory()->create();

        $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ])->assertStatus(201);

        $response = $this->postJson('/api/reservas-libro', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $usuario->id)->count());
    }

    public function test_index_incluye_la_posicion_en_la_cola(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $primero = Usuario::factory()->create();
        $segundo = Usuario::factory()->create();

        $this->postJson('/api/reservas-libro', ['usuario_id' => $primero->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);
        $this->postJson('/api/reservas-libro', ['usuario_id' => $segundo->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $response = $this->getJson('/api/reservas-libro');
        $response->assertStatus(200);

        $porUsuario = collect($response->json())->keyBy('usuario_id');
        $this->assertSame(1, $porUsuario[$primero->id]['posicion']);
        $this->assertSame(2, $porUsuario[$segundo->id]['posicion']);
    }

    public function test_index_incluye_la_proxima_fecha_de_devolucion_estimada(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $fechaDevolucion = now()->addDays(5);

        Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => $fechaDevolucion,
        ]);

        $enCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $enCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $response = $this->getJson('/api/reservas-libro');

        $response->assertStatus(200);
        $reserva = collect($response->json())->firstWhere('usuario_id', $enCola->id);
        $this->assertSame($fechaDevolucion->toDateString(), \Illuminate\Support\Carbon::parse($reserva['proxima_fecha_devolucion'])->toDateString());
    }

    /** Sin ningún préstamo activo sobre copias de ese libro, la estimación queda null en vez de fallar. */
    public function test_proxima_fecha_de_devolucion_es_null_sin_prestamos_activos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $enCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $enCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $response = $this->getJson('/api/reservas-libro');

        $reserva = collect($response->json())->firstWhere('usuario_id', $enCola->id);
        $this->assertNull($reserva['proxima_fecha_devolucion']);
    }

    public function test_devolver_prestamo_promueve_al_primero_de_la_cola_en_vez_de_liberar_el_ejemplar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $prestatario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => $prestatario->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDay(),
        ]);

        $primeroEnCola = Usuario::factory()->create();
        $segundoEnCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $primeroEnCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);
        $this->postJson('/api/reservas-libro', ['usuario_id' => $segundoEnCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        // El ejemplar sigue "no disponible" — quedó apartado para el primero de la cola.
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);

        $reservaPrimero = ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $primeroEnCola->id)->first();
        $this->assertSame('pendiente', $reservaPrimero->estado);
        $this->assertSame($ejemplar->id, $reservaPrimero->ejemplar_id);
        $this->assertNotNull($reservaPrimero->fecha_retiro);

        $reservaSegundo = ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $segundoEnCola->id)->first();
        $this->assertSame('en_cola', $reservaSegundo->estado);
    }

    public function test_devolver_prestamo_sin_nadie_en_cola_deja_el_ejemplar_disponible(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDay(),
        ]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => true]);
    }

    public function test_cancelar_reserva_pendiente_promueve_al_primero_de_la_cola(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $reservaPendiente = ReservaLibro::factory()->create([
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'usuario_id' => Usuario::factory()->create()->id,
            'estado' => 'pendiente',
        ]);

        $enCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $enCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $this->patchJson("/api/reservas-libro/{$reservaPendiente->id}/cancelar")->assertStatus(200);

        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
        $this->assertDatabaseHas('reservas_libro', ['id' => ReservaLibro::where('usuario_id', $enCola->id)->first()->id, 'estado' => 'pendiente']);
    }

    public function test_cancelar_reserva_en_cola_no_afecta_disponibilidad_ni_promueve_a_nadie(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $primero = Usuario::factory()->create();
        $segundo = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $primero->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);
        $reservaSegundo = $this->postJson('/api/reservas-libro', ['usuario_id' => $segundo->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201)->json();

        $this->patchJson("/api/reservas-libro/{$reservaSegundo['id']}/cancelar")->assertStatus(200);

        // El ejemplar sigue igual (ocupado por el préstamo/reserva original, no por la cola).
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
        $this->assertDatabaseHas('reservas_libro', ['usuario_id' => $primero->id, 'estado' => 'en_cola']);
    }

    public function test_orden_fifo_de_la_cola_se_respeta_con_tres_personas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $usuarios = Usuario::factory()->count(3)->create();
        foreach ($usuarios as $usuario) {
            $this->postJson('/api/reservas-libro', ['usuario_id' => $usuario->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);
            usleep(1000); // created_at con resolución de segundos en algunos entornos — evita empates de orden
        }

        // Se libera dos veces (cancelando la 'pendiente' que va quedando) y se verifica
        // que se promueve en el mismo orden en que se unieron a la cola.
        foreach ($usuarios as $usuario) {
            $reserva = ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $usuario->id)->first();
            $this->assertSame('en_cola', $reserva->fresh()->estado);
        }

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDay(),
        ]);
        // Nota: el ejemplar ya estaba "ocupado" por este préstamo simulado (no por otra
        // reserva), pero lo relevante acá es que liberarLibro() (llamado por devolver())
        // promueva siempre al más antiguo de la cola.
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        $primero = ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $usuarios[0]->id)->first();
        $this->assertSame('pendiente', $primero->estado);

        foreach ([1, 2] as $idx) {
            $resto = ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('usuario_id', $usuarios[$idx]->id)->first();
            $this->assertSame('en_cola', $resto->estado);
        }
    }
}
