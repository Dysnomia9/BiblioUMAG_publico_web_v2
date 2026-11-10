<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una instalación nueva (como producción, donde no corre mockup:datos) trae sus salas desde
 * las migraciones: crear_salas_iniciales y, después, salas_como_en_el_plano, que las deja
 * iguales al plano de la biblioteca (frontend/src/utils/mapaSalas.ts), lo único que tiene la
 * cantidad real. Una base que ya tiene salas no recibe otras iniciales.
 */
class SalasInicialesTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(string $archivo): object
    {
        return require database_path("migrations/{$archivo}.php");
    }

    public function test_una_base_nueva_trae_las_salas_del_plano(): void
    {
        // Las logias del plano: de la 1 a la 13 en el primer piso y de la 22 a la 30 en el segundo.
        $logias = [...range(1, 13), ...range(22, 30)];
        $this->assertSame(
            array_map(fn ($n) => 'L'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), $logias),
            Sala::where('tipo', 'logia')->enOrden()->pluck('codigo')->all(),
        );
        $this->assertSame(
            ['SEM' => 'Seminario 1', 'POS' => 'Sala de Postgrado', 'AGA' => 'Sala AGACI', 'MOR' => 'Sala Moraga', 'COO' => 'Sala Cooperación', 'SEM2' => 'Seminario 2'],
            Sala::where('tipo', 'sala')->orderBy('id')->pluck('nombre', 'codigo')->all(),
        );
        // Los seminarios, Cooperación y Moraga no se prestan a estudiantes.
        $this->assertEqualsCanonicalizing(['SEM', 'MOR', 'COO', 'SEM2'], Sala::where('solo_administracion', true)->pluck('codigo')->all());
        $this->assertTrue(Sala::where('activa', false)->doesntExist());
    }

    public function test_si_ya_hay_salas_no_crea_las_iniciales(): void
    {
        Sala::query()->delete();
        Sala::factory()->create(['nombre' => 'Logia propia']);

        $this->migracion('2026_10_08_000002_crear_salas_iniciales')->up();

        $this->assertSame(['Logia propia'], Sala::pluck('nombre')->all());
    }

    public function test_las_logias_que_no_estan_en_el_plano_salen_sin_perder_su_historial(): void
    {
        $nunca = Sala::factory()->create(['codigo' => 'L14', 'nombre' => 'Logia 14', 'tipo' => 'logia']);
        $conHistorial = Sala::factory()->create(['codigo' => 'L15', 'nombre' => 'Logia 15', 'tipo' => 'logia']);
        Reserva::factory()->create(['sala_id' => $conHistorial->id, 'estado' => 'finalizada']);

        $this->migracion('2026_11_10_000005_salas_como_en_el_plano')->up();

        // Sin reservas se elimina; con historial se da de baja y sus reservas quedan.
        $this->assertNull(Sala::find($nunca->id));
        $this->assertFalse($conHistorial->fresh()->activa);
        $this->assertSame(1, $conHistorial->reservas()->count());
    }

    public function test_una_logia_con_una_reserva_activa_no_se_da_de_baja(): void
    {
        $sala = Sala::factory()->create(['codigo' => 'L14', 'nombre' => 'Logia 14', 'tipo' => 'logia']);
        Reserva::factory()->create(['sala_id' => $sala->id, 'estado' => 'activa']);

        $this->migracion('2026_11_10_000005_salas_como_en_el_plano')->up();

        $this->assertTrue($sala->fresh()->activa);
    }

    public function test_lo_que_ya_se_ajusto_en_administracion_no_se_toca(): void
    {
        Sala::where('codigo', 'SEM')->update(['nombre' => 'Seminario Gabriela Mistral']);
        Sala::where('codigo', 'L22')->update(['capacidad' => 6]);

        $this->migracion('2026_11_10_000005_salas_como_en_el_plano')->up();

        $this->assertSame('Seminario Gabriela Mistral', Sala::where('codigo', 'SEM')->value('nombre'));
        $this->assertSame(6, Sala::where('codigo', 'L22')->value('capacidad'));
        $this->assertSame(1, Sala::where('codigo', 'L22')->count());
    }
}
