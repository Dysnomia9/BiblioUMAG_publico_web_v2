<?php

namespace Tests\Feature;

use App\Models\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una instalación nueva (como producción, donde no corre mockup:datos) trae sus salas
 * desde la migración crear_salas_iniciales; una base que ya tiene salas no se toca.
 */
class SalasInicialesTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_08_000002_crear_salas_iniciales.php');
    }

    public function test_una_base_nueva_trae_las_15_logias_y_las_3_salas(): void
    {
        $this->assertSame(15, Sala::where('tipo', 'logia')->count());
        $this->assertSame(['Sala de Seminarios', 'Sala de Postgrado', 'Sala AGACI'], Sala::where('tipo', 'sala')->orderBy('id')->pluck('nombre')->all());
        $this->assertSame('Logia 01', Sala::orderBy('id')->value('nombre'));
        $this->assertSame('Logia 15', Sala::where('tipo', 'logia')->orderByDesc('id')->value('nombre'));
        // Cada una con su código: el de la puerta y el mesón.
        $this->assertSame(
            ['L01', 'L02', 'L03', 'L04', 'L05', 'L06', 'L07', 'L08', 'L09', 'L10', 'L11', 'L12', 'L13', 'L14', 'L15', 'SEM', 'POS', 'AGA'],
            Sala::orderBy('id')->pluck('codigo')->all(),
        );
    }

    public function test_si_ya_hay_salas_no_crea_otras(): void
    {
        Sala::query()->delete();
        Sala::factory()->create(['nombre' => 'Logia propia']);

        $this->migracion()->up();

        $this->assertSame(['Logia propia'], Sala::pluck('nombre')->all());
    }
}
