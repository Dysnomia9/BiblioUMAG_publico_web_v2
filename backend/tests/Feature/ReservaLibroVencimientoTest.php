<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use App\Notifications\ReservaListaParaRetirarNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * La reserva de libro que no se retira hasta su fecha límite se cancela sola
 * (reservas-libro:vencer, cada noche), y la copia pasa al siguiente de la fila.
 */
class ReservaLibroVencimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 00:05:00'); // viernes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function copiaReservadaHasta(string $fechaRetiro): array
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $reserva = ReservaLibro::factory()->create([
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => $ejemplar->id,
            'fecha_retiro' => $fechaRetiro,
            'estado' => 'pendiente',
        ]);

        return [$ejemplar, $reserva];
    }

    public function test_la_reserva_que_no_se_retiro_a_tiempo_se_cancela_y_la_copia_queda_disponible(): void
    {
        [$ejemplar, $reserva] = $this->copiaReservadaHasta('2026-08-13');

        $this->artisan('reservas-libro:vencer')->assertSuccessful();

        $this->assertSame('cancelado', $reserva->fresh()->estado);
        $this->assertTrue($ejemplar->fresh()->disponible);

        $registro = Bitacora::where('accion', 'reservas_libro.vencer')->sole();
        $this->assertSame('Sistema', $registro->actor_nombre);
        $this->assertStringContainsString('13/08/2026', $registro->descripcion);
    }

    public function test_si_hay_lista_de_espera_la_copia_pasa_al_siguiente_y_se_le_avisa(): void
    {
        Notification::fake();
        [$ejemplar, $reserva] = $this->copiaReservadaHasta('2026-08-13');
        $siguiente = ReservaLibro::factory()->create([
            'usuario_id' => Usuario::factory()->create(['email' => 'siguiente@umag.cl'])->id,
            'libro_id' => $ejemplar->libro_id,
            'ejemplar_id' => null,
            'fecha_retiro' => null,
            'estado' => 'en_cola',
        ]);

        $this->artisan('reservas-libro:vencer')->assertSuccessful();

        $this->assertSame('cancelado', $reserva->fresh()->estado);
        $siguiente->refresh();
        $this->assertSame('pendiente', $siguiente->estado);
        $this->assertSame($ejemplar->id, $siguiente->ejemplar_id);
        $this->assertNotNull($siguiente->fecha_retiro);
        $this->assertFalse($ejemplar->fresh()->disponible);
        Notification::assertSentTo($siguiente->usuario, ReservaListaParaRetirarNotification::class);
    }

    public function test_la_que_vence_hoy_todavia_se_puede_retirar(): void
    {
        [$ejemplar, $reserva] = $this->copiaReservadaHasta('2026-08-14');
        $enCola = ReservaLibro::factory()->create(['estado' => 'en_cola', 'ejemplar_id' => null, 'fecha_retiro' => null]);

        $this->artisan('reservas-libro:vencer')->assertSuccessful();

        $this->assertSame('pendiente', $reserva->fresh()->estado);
        $this->assertFalse($ejemplar->fresh()->disponible);
        $this->assertSame('en_cola', $enCola->fresh()->estado);
        $this->assertSame(0, Bitacora::where('accion', 'reservas_libro.vencer')->count());
    }

    public function test_el_vencimiento_esta_programado_cada_noche(): void
    {
        $tarea = collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains($evento->command, 'reservas-libro:vencer'));

        $this->assertNotNull($tarea, 'Se esperaba la tarea reservas-libro:vencer en routes/console.php.');
        $this->assertSame('5 0 * * *', $tarea->expression);
    }
}
