<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Notifications\MultaGeneradaNotification;
use App\Notifications\ReservaListaParaRetirarNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cubre los dos disparadores de notificación implementados (ver .env.example: sin
 * SMTP configurado, el canal 'mail' solo escribe a storage/logs/laravel.log — estos
 * tests no dependen de eso, usan Notification::fake() para no tocar mail real).
 */
class NotificacionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_promover_de_la_cola_notifica_al_usuario_promovido(): void
    {
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDay(),
        ]);
        $primeroEnCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $primeroEnCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        Notification::assertSentTo($primeroEnCola, ReservaListaParaRetirarNotification::class);
    }

    public function test_cancelar_reserva_en_cola_no_notifica_a_nadie(): void
    {
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $enCola = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $enCola->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(201);
        $reserva = ReservaLibro::where('usuario_id', $enCola->id)->first();

        $this->patchJson("/api/reservas-libro/{$reserva->id}/cancelar")->assertStatus(200);

        Notification::assertNothingSent();
    }

    public function test_devolver_prestamo_con_multa_notifica_al_usuario(): void
    {
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $usuario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->subDays(3),
        ]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        Notification::assertSentTo($usuario, MultaGeneradaNotification::class);
    }

    public function test_devolver_prestamo_sin_atraso_no_notifica_multa(): void
    {
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $usuario = Usuario::factory()->create();
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDay(),
        ]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        Notification::assertNotSentTo($usuario, MultaGeneradaNotification::class);
    }

    public function test_usuario_sin_email_no_recibe_notificacion(): void
    {
        Notification::fake();
        Sanctum::actingAs(Staff::factory()->create());

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $usuario = Usuario::factory()->create(['email' => null]);
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'ejemplar_id' => $ejemplar->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->subDays(3),
        ]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);

        Notification::assertNothingSent();
    }
}
