<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\ReglaPrestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Uso en sala: el material se lee en la biblioteca y se devuelve el mismo día. */
class PrestamoEnSalaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Un miércoles: la biblioteca atiende.
        Carbon::setTestNow('2026-07-08 11:00:00');
        Sanctum::actingAs(Staff::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enSala(Usuario $usuario, Ejemplar $ejemplar)
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'en_sala' => true,
        ]);
    }

    public function test_se_presta_en_sala_con_devolucion_hoy_y_sin_pedir_fechas(): void
    {
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->create();

        $this->enSala($usuario, $ejemplar)->assertCreated()->assertJsonPath('en_sala', true);

        $prestamo = Prestamo::firstOrFail();
        $this->assertSame('2026-07-08', $prestamo->fecha_devolucion->toDateString());
        $this->assertFalse($ejemplar->fresh()->disponible);
    }

    public function test_el_material_que_no_se_presta_a_domicilio_si_se_presta_en_sala(): void
    {
        $referencia = TipoMaterial::factory()->create();
        ReglaPrestamo::create(['tipo_material_id' => $referencia->id, 'prestable' => false]);
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory()->state(['tipo_material_id' => $referencia->id]))->create();

        $condiciones = $this->getJson("/api/prestamos/condiciones?usuario_id={$usuario->id}&codigo_barras={$ejemplar->codigo_barras}")->assertOk();
        $this->assertStringContainsString('no se presta a domicilio', $condiciones->json('motivo_rechazo'));
        $this->assertNull($condiciones->json('motivo_rechazo_en_sala'));

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id, 'tipo_item' => 'libro', 'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => '2026-07-08', 'fecha_devolucion' => '2026-07-15',
        ])->assertStatus(409);

        $this->enSala($usuario, $ejemplar)->assertCreated();
    }

    public function test_en_sala_no_cuenta_para_el_maximo_de_libros_a_domicilio(): void
    {
        $usuario = Usuario::factory()->create();
        [$primero, $segundo, $tercero] = Ejemplar::factory()->count(3)->create();

        // Sin reglas: un libro a domicilio a la vez.
        $this->enSala($usuario, $primero)->assertCreated();
        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id, 'tipo_item' => 'libro', 'codigo_barras' => $segundo->codigo_barras,
            'fecha_prestamo' => '2026-07-08', 'fecha_devolucion' => '2026-07-15',
        ])->assertCreated();
        $this->enSala($usuario, $tercero)->assertCreated();
    }

    public function test_las_multas_y_las_cuentas_vencidas_bloquean_tambien_el_uso_en_sala(): void
    {
        $ejemplar = Ejemplar::factory()->create();

        $conMulta = Usuario::factory()->create();
        Multa::factory()->create(['usuario_id' => $conMulta->id, 'estado' => 'pendiente']);
        $this->enSala($conMulta, $ejemplar)->assertStatus(409);

        $vencida = Usuario::factory()->create(['vigente_hasta' => '2026-03-31']);
        $this->enSala($vencida, $ejemplar)->assertStatus(409);

        $this->assertSame(0, Prestamo::count());
    }

    public function test_si_no_lo_devuelve_el_mismo_dia_queda_vencido_y_se_multa(): void
    {
        $usuario = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->create();
        $prestamo = Prestamo::findOrFail($this->enSala($usuario, $ejemplar)->json('id'));

        // Devolverlo en la tarde del mismo día: sin multa.
        Carbon::setTestNow('2026-07-08 19:30:00');
        $this->assertSame(0, Prestamo::vencidos()->count());

        // Al día siguiente sigue sin devolver: vencido, bloquea y al devolverlo se multa.
        Carbon::setTestNow('2026-07-09 10:00:00');
        $this->assertSame(1, Prestamo::vencidos()->count());
        $this->enSala($usuario, Ejemplar::factory()->create())->assertStatus(409);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk();
        $this->assertSame(1, Multa::where('prestamo_id', $prestamo->id)->count());
    }

    public function test_devuelto_el_mismo_dia_no_tiene_multa(): void
    {
        $prestamo = Prestamo::findOrFail($this->enSala(Usuario::factory()->create(), Ejemplar::factory()->create())->json('id'));

        Carbon::setTestNow('2026-07-08 18:00:00');
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk();

        $this->assertSame(0, Multa::count());
    }

    public function test_no_entrega_en_sala_la_copia_apartada_ni_cierra_la_reserva_del_titulo(): void
    {
        $usuario = Usuario::factory()->create();
        $apartada = Ejemplar::factory()->create(['disponible' => false]);
        ReservaLibro::factory()->create(['usuario_id' => $usuario->id, 'libro_id' => $apartada->libro_id, 'ejemplar_id' => $apartada->id, 'estado' => 'pendiente']);

        $this->enSala($usuario, $apartada)->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Para llevar'));

        // Otra copia del mismo título, leída en sala: su reserva sigue en pie.
        $otra = Ejemplar::factory()->create(['libro_id' => $apartada->libro_id]);
        $this->enSala($usuario, $otra)->assertCreated();
        $this->assertSame('pendiente', ReservaLibro::firstOrFail()->estado);
    }

    public function test_el_reporte_separa_el_uso_en_sala(): void
    {
        Prestamo::factory()->count(2)->create(['tipo_item' => 'libro']);
        Prestamo::factory()->create(['tipo_item' => 'libro', 'en_sala' => true]);
        Prestamo::factory()->create(['tipo_item' => 'notebook']);

        $this->getJson('/api/reportes/resumen?tab=prestamos')
            ->assertOk()
            ->assertJsonPath('porTipoPrestamo', [
                ['label' => 'bibliografico', 'value' => 2],
                ['label' => 'en_sala', 'value' => 1],
                ['label' => 'recursos_apoyo', 'value' => 1],
            ]);

        $this->getJson('/api/reportes/resumen?tab=prestamos&tipo_prestamo=en_sala')->assertOk()->assertJsonPath('total', 1);
    }
}
