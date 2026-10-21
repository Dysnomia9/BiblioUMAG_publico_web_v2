<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Si un préstamo está atrasado sale de su fecha de devolución (Prestamo::scopeVencidos()),
 * no de un estado guardado: el dashboard y el Listado de préstamos cuentan lo mismo.
 */
class PrestamoVencidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 10:00:00'); // viernes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_esta_atrasado_el_que_vencio_ayer_y_no_el_que_vence_hoy(): void
    {
        $vencido = Prestamo::factory()->create(['fecha_devolucion' => '2026-08-13 18:00:00']);
        Prestamo::factory()->create(['fecha_devolucion' => '2026-08-14 00:00:00']);
        Prestamo::factory()->create(['fecha_devolucion' => '2026-08-13 18:00:00', 'estado' => 'devuelto', 'fecha_devolucion_real' => '2026-08-13 17:00:00']);
        // Recurso de apoyo, sin fecha de devolución fija.
        Prestamo::factory()->create(['tipo_item' => 'audifonos', 'fecha_devolucion' => null]);

        $this->assertSame([$vencido->id], Prestamo::vencidos()->pluck('id')->all());

        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->getJson('/api/dashboard/resumen')->assertOk()
            ->assertJsonPath('prestamosActivos', 3)
            ->assertJsonPath('prestamosAtrasados', 1);

        $listado = $this->getJson('/api/prestamos?vista=vencidos')->assertOk();
        $this->assertSame([$vencido->id], array_column($listado->json('data'), 'id'));
        $this->assertSame(1, $listado->json('conteos.vencidos'));
    }

    public function test_la_base_ya_no_acepta_el_estado_atrasado(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_prestamos_estado');
        Prestamo::factory()->create(['estado' => 'atrasado']);
    }
}
