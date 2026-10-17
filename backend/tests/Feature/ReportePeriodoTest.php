<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportePeriodoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0));
        Sanctum::actingAs(Staff::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prestamo(string $fechaHora, string $tipo = 'estudiante'): void
    {
        Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create(['tipo' => $tipo])->id,
            'fecha_prestamo' => Carbon::parse($fechaHora),
        ]);
    }

    public function test_el_mes_cuenta_solo_sus_prestamos_y_se_compara_con_el_anterior(): void
    {
        $this->prestamo('2026-09-01 10:30');
        $this->prestamo('2026-09-06 11:15');
        $this->prestamo('2026-09-14 16:00', 'docente');
        $this->prestamo('2026-08-10 10:00');
        $this->prestamo('2026-08-20 10:00');
        $this->prestamo('2026-07-03 10:00');

        $respuesta = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=mes&fecha=2026-09-15')->assertOk();

        $respuesta->assertJsonPath('rango.desde', '2026-09-01')
            ->assertJsonPath('rango.hasta', '2026-09-30')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('totalAnterior', 2)
            ->assertJsonPath('horarioPico.desde', '10:00')
            ->assertJsonPath('horarioPico.hasta', '12:00')
            ->assertJsonPath('horarioPico.value', 2);

        // El mes se divide en semanas de lunes a domingo, recortadas al mes: el 1 de
        // septiembre es martes, así que la primera va del 1 al 6.
        $serie = $respuesta->json('serie');
        $this->assertSame(['1–6', '7–13', '14–20', '21–27', '28–30'], array_column($serie, 'label'));
        $this->assertSame([2, 0, 1, 0, 0], array_column($serie, 'value'));
    }

    public function test_la_semana_se_divide_por_dias(): void
    {
        $this->prestamo('2026-09-14 10:00');
        $this->prestamo('2026-09-19 10:00');

        $serie = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=semana&fecha=2026-09-15')->assertOk()->json('serie');

        $this->assertSame(['lun 14', 'mar 15', 'mié 16', 'jue 17', 'vie 18', 'sáb 19', 'dom 20'], array_column($serie, 'label'));
        $this->assertSame([1, 0, 0, 0, 0, 1, 0], array_column($serie, 'value'));
        // El domingo la biblioteca no abre.
        $this->assertSame(['label' => 'dom 20', 'value' => 0, 'finDeSemana' => true, 'cerrado' => true], $serie[6]);
    }

    public function test_el_dia_se_divide_por_horas_de_atencion(): void
    {
        $this->prestamo('2026-09-15 09:20');
        $this->prestamo('2026-09-14 09:20');

        $respuesta = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=dia&fecha=2026-09-15')->assertOk();

        $serie = collect($respuesta->json('serie'));
        $this->assertSame(['08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20'], $serie->pluck('label')->all());
        $this->assertSame(1, $serie->firstWhere('label', '09')['value']);
        $respuesta->assertJsonPath('total', 1)->assertJsonPath('totalAnterior', 1);
    }

    public function test_el_dia_usa_el_horario_de_atencion_de_ese_dia(): void
    {
        $this->prestamo('2026-09-19 10:00');

        $sabado = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=dia&fecha=2026-09-19')->assertOk();
        $this->assertSame(['09', '10', '11', '12', '13'], collect($sabado->json('serie'))->pluck('label')->all());
        $this->assertSame(['09h', '10h', '11h', '12h', '13h'], collect($sabado->json('porHora'))->pluck('label')->all());

        $domingo = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=dia&fecha=2026-09-20')->assertOk();
        $domingo->assertJsonCount(0, 'serie')->assertJsonCount(0, 'porHora')->assertJsonPath('horarioPico', null);
    }

    public function test_el_anio_se_divide_por_meses_y_el_semestre_va_de_julio_a_diciembre(): void
    {
        $this->prestamo('2026-03-10 10:00');
        $this->prestamo('2026-09-10 10:00');

        $anio = collect($this->getJson('/api/reportes/resumen?tab=prestamos&periodo=anio&fecha=2026-09-15')->json('serie'));
        $this->assertCount(12, $anio);
        $this->assertSame('mar', $anio[2]['label']);
        $this->assertSame(1, $anio[2]['value']);

        $semestre = $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=semestre&fecha=2026-09-15')
            ->assertJsonPath('rango.desde', '2026-07-01')
            ->assertJsonPath('rango.hasta', '2026-12-31')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('totalAnterior', 1);
        $this->assertSame(['jul', 'ago', 'sep', 'oct', 'nov', 'dic'], collect($semestre->json('serie'))->pluck('label')->all());
    }

    public function test_la_tabla_por_carrera_trae_todas_las_carreras(): void
    {
        foreach (range(1, 10) as $i) {
            Prestamo::factory()->create([
                'usuario_id' => Usuario::factory()->create(['carrera' => "Carrera {$i}"])->id,
                'fecha_prestamo' => Carbon::parse('2026-09-10 10:00'),
            ]);
        }

        $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=mes&fecha=2026-09-15')
            ->assertOk()
            ->assertJsonCount(10, 'porCarrera');
    }
}
