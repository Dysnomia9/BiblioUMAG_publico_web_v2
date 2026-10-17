<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Un préstamo no vence ni una reserva se retira un día en que la biblioteca no atiende (config/biblioteca.php). */
class FechasConAtencionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_personal_puede_consultar_el_horario_de_la_biblioteca(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->getJson('/api/configuracion/horario')->assertOk()
            ->assertJsonPath('6', ['09:00', '14:00'])
            ->assertJsonPath('7', null);
    }

    public function test_la_devolucion_de_un_prestamo_no_puede_caer_en_domingo(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $prestamo = fn (string $devolucion) => $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => '2026-07-08',
            'fecha_devolucion' => $devolucion,
        ]);

        $prestamo('2026-07-12')->assertStatus(422)
            ->assertJsonPath('errors.fecha_devolucion.0', 'La biblioteca no atiende los domingos: elige otro día para la devolución.');
        $this->assertDatabaseCount('prestamos', 0);

        $prestamo('2026-07-11')->assertStatus(201);
    }

    public function test_el_retiro_de_una_reserva_no_puede_caer_en_domingo(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_reserva' => '2026-07-08',
            'fecha_retiro' => '2026-07-12',
        ])->assertStatus(422)
            ->assertJsonPath('errors.fecha_retiro.0', 'La biblioteca no atiende los domingos: elige otro día para el retiro.');
        $this->assertDatabaseCount('reservas_libro', 0);
    }

    public function test_el_plazo_automatico_de_retiro_se_corre_al_lunes(): void
    {
        // Jueves + 3 días de plazo = domingo: queda para el lunes.
        Carbon::setTestNow('2026-07-09 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $apartada = $this->postJson('/api/reservas-libro', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
        ])->assertStatus(201)->json('id');
        $this->assertSame('2026-07-13', ReservaLibro::find($apartada)->fecha_retiro->toDateString());

        // Lo mismo cuando la copia pasa sola al primero de la lista de espera.
        $prestado = Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'ejemplar_id' => $prestado->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => '2026-07-10',
        ]);
        $enEspera = Usuario::factory()->create();
        $this->postJson('/api/reservas-libro', ['usuario_id' => $enEspera->id, 'codigo_barras' => $prestado->codigo_barras])->assertStatus(201);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk();

        $this->assertSame('2026-07-13', ReservaLibro::where('usuario_id', $enEspera->id)->first()->fecha_retiro->toDateString());
    }
}
