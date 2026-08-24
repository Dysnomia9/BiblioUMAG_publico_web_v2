<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReporteResumenLibrosSalasTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_libros_rankea_por_titulo_prestado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $masPrestado = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'El más prestado']))->create();
        $menosPrestado = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'El menos prestado']))->create();

        Prestamo::factory()->count(3)->create([
            'ejemplar_id' => $masPrestado->id,
            'tipo_item' => 'libro',
            'libro_titulo' => 'El más prestado',
        ]);
        Prestamo::factory()->create([
            'ejemplar_id' => $menosPrestado->id,
            'tipo_item' => 'libro',
            'libro_titulo' => 'El menos prestado',
        ]);

        $response = $this->getJson('/api/reportes/resumen?tab=libros');

        $response->assertStatus(200);
        $porLibro = $response->json('porLibro');
        $this->assertSame('El más prestado', $porLibro[0]['label']);
        $this->assertSame(3, $porLibro[0]['value']);
    }

    public function test_tab_prestamos_no_incluye_porlibro(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $response = $this->getJson('/api/reportes/resumen?tab=prestamos');

        $response->assertStatus(200)->assertJsonPath('porLibro', []);
    }

    public function test_tab_logias_rankea_por_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $salaPopular = Sala::factory()->create(['nombre' => 'Logia Popular']);
        $salaTranquila = Sala::factory()->create(['nombre' => 'Logia Tranquila']);

        Reserva::factory()->count(2)->create(['sala_id' => $salaPopular->id, 'usuario_id' => Usuario::factory()]);
        Reserva::factory()->create(['sala_id' => $salaTranquila->id, 'usuario_id' => Usuario::factory()]);

        $response = $this->getJson('/api/reportes/resumen?tab=logias');

        $response->assertStatus(200);
        $porSala = $response->json('porSala');
        $this->assertSame('Logia Popular', $porSala[0]['label']);
        $this->assertSame(2, $porSala[0]['value']);
    }

    public function test_tab_logias_desglosa_horas_por_cada_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $logiaA = Sala::factory()->create(['nombre' => 'Logia A']);
        $logiaB = Sala::factory()->create(['nombre' => 'Logia B']);

        Reserva::factory()->create(['sala_id' => $logiaA->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
        Reserva::factory()->create(['sala_id' => $logiaA->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
        Reserva::factory()->create(['sala_id' => $logiaB->id, 'usuario_id' => Usuario::factory(), 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);

        $response = $this->getJson('/api/reportes/resumen?tab=logias');

        $response->assertStatus(200);
        $porHoraPorSala = collect($response->json('porHoraPorSala'))->keyBy('sala');

        $horasA = collect($porHoraPorSala['Logia A']['horas'])->keyBy('label');
        $this->assertSame(2, $horasA['10h']['value']);
        $this->assertSame(0, $horasA['16h']['value']);

        $horasB = collect($porHoraPorSala['Logia B']['horas'])->keyBy('label');
        $this->assertSame(1, $horasB['16h']['value']);
        $this->assertSame(0, $horasB['10h']['value']);
    }

    public function test_tab_prestamos_no_incluye_porhoraporsala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Prestamo::factory()->create(['tipo_item' => 'audifonos']);

        $response = $this->getJson('/api/reportes/resumen?tab=prestamos');

        $response->assertStatus(200)->assertJsonPath('porHoraPorSala', []);
    }
}
