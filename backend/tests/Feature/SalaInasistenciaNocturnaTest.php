<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** salas:liberar-vencidas: cada noche, las reservas a las que nadie llegó quedan como inasistencia. */
class SalaInasistenciaNocturnaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_reserva_de_ayer_a_la_que_nadie_llego_queda_como_inasistencia(): void
    {
        Carbon::setTestNow('2026-07-09 08:00:00'); // jueves
        $usuario = Usuario::factory()->create();
        $reserva = fn (array $datos) => Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => Sala::factory()->create()->id,
            'usuario_id' => $usuario->id,
            'rut_usuario' => $usuario->rut,
            ...$datos,
        ]);
        $nadieLlego = $reserva(['fecha' => '2026-07-09']);
        $llaveAfuera = $reserva(['fecha' => '2026-07-09', 'hora_prestamo_real' => '2026-07-09 10:03:00']);
        $deManana = $reserva(['fecha' => '2026-07-10']);

        Carbon::setTestNow('2026-07-10 00:10:00');
        $this->artisan('salas:liberar-vencidas')->expectsOutputToContain('1 reserva(s)')->assertSuccessful();

        $this->assertSame('no_show', $nadieLlego->fresh()->estado);
        // La llave que no volvió sigue a la vista en el listado ("llave sin devolver").
        $this->assertSame('activa', $llaveAfuera->fresh()->estado);
        $this->assertSame('activa', $deManana->fresh()->estado);
    }

    public function test_esta_programada_cada_noche(): void
    {
        $tarea = collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains($evento->command, 'salas:liberar-vencidas'));

        $this->assertNotNull($tarea, 'Se esperaba la tarea salas:liberar-vencidas en routes/console.php.');
        $this->assertSame('10 0 * * *', $tarea->expression);
    }
}
