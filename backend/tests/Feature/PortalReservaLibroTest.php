<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalReservaLibroTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_puede_reservar_un_libro_disponible_por_su_cuenta(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $response = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id]);

        $response->assertStatus(201)->assertJsonPath('estado', 'pendiente');
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => false]);
        $this->assertDatabaseHas('reservas_libro', ['usuario_id' => $usuario->id, 'libro_id' => $ejemplar->libro_id, 'ejemplar_id' => $ejemplar->id, 'estado' => 'pendiente']);
    }

    public function test_usuario_puede_unirse_a_la_cola_de_un_libro_ocupado_por_su_cuenta(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);

        $response = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id]);

        $response->assertStatus(201)->assertJsonPath('estado', 'en_cola');
    }

    public function test_usuario_ve_su_posicion_en_la_cola_en_su_listado(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $otro = Usuario::factory()->create();
        Sanctum::actingAs($otro);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])->assertStatus(201);

        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])->assertStatus(201);

        $response = $this->getJson('/api/mi/reservas-libro');

        $response->assertStatus(200);
        $this->assertSame(2, collect($response->json())->firstWhere('usuario_id', $usuario->id)['posicion']);
    }

    public function test_usuario_ve_la_proxima_fecha_de_devolucion_estimada_en_su_listado(): void
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $fechaDevolucion = now()->addDays(3);
        Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => $fechaDevolucion,
        ]);

        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])->assertStatus(201);

        $response = $this->getJson('/api/mi/reservas-libro');

        $reserva = collect($response->json())->firstWhere('usuario_id', $usuario->id);
        $this->assertSame($fechaDevolucion->toDateString(), \Illuminate\Support\Carbon::parse($reserva['proxima_fecha_devolucion'])->toDateString());
    }

    public function test_usuario_no_puede_cancelar_la_reserva_de_otro(): void
    {
        $dueno = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $reserva = ReservaLibro::factory()->create([
            'usuario_id' => $dueno->id,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'estado' => 'pendiente',
        ]);

        $otroUsuario = Usuario::factory()->create();
        Sanctum::actingAs($otroUsuario);

        $response = $this->patchJson("/api/mi/reservas-libro/{$reserva->id}/cancelar");

        $response->assertStatus(403);
        $this->assertDatabaseHas('reservas_libro', ['id' => $reserva->id, 'estado' => 'pendiente']);
    }

    public function test_usuario_puede_cancelar_su_propia_reserva(): void
    {
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $reserva = ReservaLibro::factory()->create([
            'usuario_id' => $usuario->id,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'estado' => 'pendiente',
        ]);

        Sanctum::actingAs($usuario);
        $response = $this->patchJson("/api/mi/reservas-libro/{$reserva->id}/cancelar");

        $response->assertStatus(200)->assertJsonPath('estado', 'cancelado');
        $this->assertDatabaseHas('ejemplares', ['id' => $ejemplar->id, 'disponible' => true]);
    }

    public function test_reservar_por_libro_prefiere_una_copia_disponible_sobre_una_ocupada(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $libro = Libro::factory()->create();
        $ocupada = Ejemplar::factory()->for($libro)->create(['disponible' => false]);
        $disponible = Ejemplar::factory()->for($libro)->create(['disponible' => true]);

        $response = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $libro->id]);

        $response->assertStatus(201)->assertJsonPath('estado', 'pendiente')->assertJsonPath('ejemplar_id', $disponible->id);
        $this->assertDatabaseHas('ejemplares', ['id' => $ocupada->id, 'disponible' => false]);
    }

    public function test_reservar_libro_sin_ejemplares_en_estante_devuelve_404(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $libro = Libro::factory()->create();
        Ejemplar::factory()->for($libro)->create(['estado_proceso' => 'inventario']);

        $response = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $libro->id]);

        $response->assertStatus(404);
    }

    public function test_usuario_tiene_una_sola_reserva_de_libro_a_la_vez(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        [$primero, $segundo] = Ejemplar::factory()->count(2)->sequence(fn () => ['libro_id' => Libro::factory()])->create();

        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $primero->libro_id])->assertStatus(201);
        $response = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id]);

        $response->assertStatus(409)->assertJsonPath('message', 'Ya tienes una reserva de libro activa (por retirar o en lista de espera), el máximo a la vez: retírala o cancélala en «Mis reservas» antes de reservar otro libro.');
        $this->assertDatabaseHas('ejemplares', ['id' => $segundo->id, 'disponible' => true]);
        $this->assertDatabaseMissing('reservas_libro', ['usuario_id' => $usuario->id, 'libro_id' => $segundo->libro_id]);
    }

    public function test_cancelar_una_reserva_libera_el_cupo_para_otra(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        [$primero, $segundo] = Ejemplar::factory()->count(2)->sequence(fn () => ['libro_id' => Libro::factory()])->create();

        $reserva = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $primero->libro_id])->assertStatus(201)->json();
        $this->patchJson("/api/mi/reservas-libro/{$reserva['id']}/cancelar")->assertStatus(200);

        // Pasada la espera que sigue a cancelar (ver test_quien_cancela_espera_...).
        $this->travel(5)->minutes();
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id])->assertStatus(201)->assertJsonPath('estado', 'pendiente');
    }

    /** La lista de espera también es su reserva: así la cola nunca le aparta dos libros. */
    public function test_las_listas_de_espera_cuentan_dentro_de_la_misma_reserva(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        [$ocupado, $otroOcupado] = Ejemplar::factory()->count(2)->sequence(fn () => ['libro_id' => Libro::factory()])->create(['disponible' => false]);
        $disponible = Ejemplar::factory()->for(Libro::factory())->create();

        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ocupado->libro_id])->assertStatus(201)->assertJsonPath('estado', 'en_cola');
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $otroOcupado->libro_id])->assertStatus(409);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $disponible->libro_id])->assertStatus(409);

        $this->assertSame(1, ReservaLibro::where('usuario_id', $usuario->id)->count());
        $this->assertDatabaseHas('ejemplares', ['id' => $disponible->id, 'disponible' => true]);
    }

    public function test_condiciones_anticipan_la_fecha_de_retiro_que_tendra_la_reserva(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $condiciones = $this->getJson('/api/mi/reservas-libro/condiciones');
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])->assertStatus(201);

        $condiciones->assertStatus(200)->assertJson([
            'dias_para_retirar' => config('reservas_libro.dias_para_retirar'),
            'maximo_por_persona' => 1,
        ]);
        $reserva = ReservaLibro::where('usuario_id', $usuario->id)->first();
        $this->assertSame($reserva->fecha_retiro->toDateString(), $condiciones->json('fecha_retiro'));
        $this->assertNull($condiciones->json('bloqueo'));
    }

    public function test_condiciones_avisan_el_bloqueo_por_multas_pendientes(): void
    {
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        Multa::factory()->for($usuario)->create(['monto' => 1500]);

        $this->getJson('/api/mi/reservas-libro/condiciones')
            ->assertStatus(200)
            ->assertJsonPath('bloqueo', 'Tienes 1 multa pendiente por $1.500: debes pagarla en la biblioteca antes de reservar libros.');
    }

    public function test_staff_no_puede_usar_las_rutas_del_portal(): void
    {
        Sanctum::actingAs(\App\Models\Staff::factory()->create());

        $response = $this->getJson('/api/mi/reservas-libro');

        $response->assertStatus(403);
    }

    public function test_quien_cancela_espera_unos_minutos_antes_de_pedir_otro_libro(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        [$primero, $segundo] = Ejemplar::factory()->count(2)->sequence(fn () => ['libro_id' => Libro::factory()])->create();

        $reserva = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $primero->libro_id])->assertStatus(201)->json();
        $this->travel(1)->minutes();
        $this->patchJson("/api/mi/reservas-libro/{$reserva['id']}/cancelar")->assertStatus(200);

        $aviso = 'Cancelaste una reserva hace poco. Para evitar reservas y cancelaciones seguidas, hay que esperar 5 minutos: podrás pedir un libro de nuevo a las 10:06.';
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $primero->libro_id])->assertStatus(429)->assertJsonPath('message', $aviso);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id])->assertStatus(429);
        $this->getJson('/api/mi/reservas-libro/condiciones')->assertJsonPath('espera', $aviso)->assertJsonPath('bloqueo', null);
        $this->assertSame(1, ReservaLibro::where('usuario_id', $usuario->id)->count());

        $this->travel(4)->minutes();
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id])->assertStatus(429);

        $this->travel(1)->minutes();
        $this->getJson('/api/mi/reservas-libro/condiciones')->assertJsonPath('espera', null)->assertJsonPath('espera_hasta', null);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id])->assertStatus(201);
    }

    public function test_si_cancela_el_meson_la_persona_no_queda_esperando(): void
    {
        $usuario = Usuario::factory()->create();
        [$primero, $segundo] = Ejemplar::factory()->count(2)->sequence(fn () => ['libro_id' => Libro::factory()])->create();
        Sanctum::actingAs($usuario);
        $reserva = $this->postJson('/api/mi/reservas-libro', ['libro_id' => $primero->libro_id])->assertStatus(201)->json();

        Sanctum::actingAs(Staff::factory()->admin()->create());
        $this->patchJson("/api/reservas-libro/{$reserva['id']}/cancelar")->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $segundo->libro_id])->assertStatus(201);
    }

    public function test_desde_el_portal_no_se_cancela_una_reserva_ya_retirada(): void
    {
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $retirada = ReservaLibro::factory()->create([
            'usuario_id' => $usuario->id,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'estado' => 'retirado',
        ]);

        Sanctum::actingAs($usuario);
        $this->patchJson("/api/mi/reservas-libro/{$retirada->id}/cancelar")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este libro ya se retiró: la reserva queda en tu historial y no se puede cancelar.');

        $this->assertSame('retirado', $retirada->fresh()->estado);
        $this->assertFalse($ejemplar->fresh()->disponible);
    }
}
