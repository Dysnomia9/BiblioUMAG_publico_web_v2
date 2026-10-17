<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Sala;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalActividadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_inicio_resume_solo_lo_vigente_del_propio_usuario(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $usuario = Usuario::factory()->create();
        $otro = Usuario::factory()->create();
        $sala = Sala::factory()->create(['nombre' => 'Logia 04']);

        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => Carbon::today()->setTime(9, 15)]);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => Carbon::yesterday()->setTime(18, 0)]);

        Prestamo::factory()->create(['usuario_id' => $usuario->id, 'libro_titulo' => 'Flora (copia 1)', 'fecha_devolucion' => now()->addDays(2)]);
        Prestamo::factory()->create(['usuario_id' => $usuario->id, 'estado' => 'devuelto', 'fecha_devolucion_real' => now()]);
        Prestamo::factory()->create(['usuario_id' => $otro->id]);

        // Integrante (no titular) de una reserva de hoy; las canceladas o ya terminadas no cuentan.
        Reserva::factory()->conParticipantes([$otro, $usuario])->create([
            'sala_id' => $sala->id, 'usuario_id' => $otro->id, 'rut_usuario' => $otro->rut,
            'hora_inicio' => '11:00:00', 'hora_fin' => '12:00:00',
        ]);
        Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => $sala->id, 'usuario_id' => $usuario->id, 'estado' => 'cancelada',
            'hora_inicio' => '13:00:00', 'hora_fin' => '14:00:00',
        ]);
        Reserva::factory()->conParticipantes([$usuario])->create([
            'sala_id' => $sala->id, 'usuario_id' => $usuario->id,
            'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00',
        ]);

        ReservaLibro::factory()->create(['usuario_id' => $usuario->id, 'libro_id' => Libro::factory()->create(['titulo' => 'Memorice'])->id]);
        Multa::factory()->create(['usuario_id' => $usuario->id, 'monto' => 3500]);
        Multa::factory()->create(['usuario_id' => $otro->id]);

        Sanctum::actingAs($usuario);
        $respuesta = $this->getJson('/api/mi/actividad')->assertOk();

        $respuesta->assertJsonPath('entrada_hoy', '09:15')
            ->assertJsonCount(1, 'prestamos')
            ->assertJsonPath('prestamos.0.titulo', 'Flora (copia 1)')
            ->assertJsonPath('prestamos.0.fecha_devolucion', now()->addDays(2)->toDateString())
            ->assertJsonCount(1, 'reservas_sala')
            ->assertJsonPath('reservas_sala.0.sala', 'Logia 04')
            ->assertJsonPath('reservas_sala.0.hora_inicio', '11:00')
            ->assertJsonCount(1, 'reservas_libro')
            ->assertJsonPath('reservas_libro.0.titulo', 'Memorice')
            ->assertJsonPath('reservas_libro.0.estado', 'pendiente')
            ->assertJsonPath('multas.cantidad', 1)
            ->assertJsonPath('multas.monto_total', 3500);
    }

    public function test_sin_ingreso_hoy_la_entrada_viene_vacia(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());

        $this->getJson('/api/mi/actividad')
            ->assertOk()
            ->assertJsonPath('entrada_hoy', null)
            ->assertJsonPath('multas.cantidad', 0);
    }
}
