<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La Sala Moraga pasa a ser Tesis/Posgrado (TPG) y se agregan las logias del hospital. */
class TesisPosgradoYHospitalTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_19_000002_tesis_posgrado_y_logias_hospital.php');
    }

    public function test_moraga_pasa_a_tesis_posgrado_con_su_historial(): void
    {
        $sala = Sala::where('codigo', 'TPG')->sole();
        $sala->update(['codigo' => 'MOR', 'nombre' => 'Sala Moraga']);
        Reserva::factory()->create(['sala_id' => $sala->id, 'estado' => 'finalizada']);

        $this->migracion()->up();

        $sala->refresh();
        $this->assertSame(['TPG', 'Sala Tesis/Posgrado', true], [$sala->codigo, $sala->nombre, $sala->solo_administracion]);
        $this->assertSame(1, $sala->reservas()->count());
        $this->assertFalse(Sala::where('codigo', 'MOR')->exists());
    }

    public function test_las_logias_del_hospital_se_prestan(): void
    {
        $this->assertSame(
            ['HCM1' => 'Hospital Logia 1', 'HCM2' => 'Hospital Logia 2'],
            Sala::whereIn('codigo', ['HCM1', 'HCM2'])->where('tipo', 'logia')->where('solo_administracion', false)->orderBy('codigo')->pluck('nombre', 'codigo')->all(),
        );

        // Van al final, después de las logias y salas de la biblioteca (línea de tiempo del portal).
        $this->assertSame(['HCM1', 'HCM2'], Sala::where('activa', true)->enOrden()->pluck('codigo')->take(-2)->values()->all());

        // Volver a correrla no las duplica.
        $this->migracion()->up();
        $this->assertSame(2, Sala::whereIn('codigo', ['HCM1', 'HCM2'])->count());
    }
}
