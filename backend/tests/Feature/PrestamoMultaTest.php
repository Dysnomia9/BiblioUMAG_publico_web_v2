<?php

namespace Tests\Feature;

use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestamoMultaTest extends TestCase
{
    use RefreshDatabase;

    private function prestamoLibro(array $atributos = []): Prestamo
    {
        return Prestamo::factory()->create($atributos + [
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'estado' => 'activo',
        ]);
    }

    public function test_devolucion_a_tiempo_no_genera_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = $this->prestamoLibro(['fecha_devolucion' => now()->addDays(2)]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")
            ->assertStatus(200)
            ->assertJsonCount(0, 'multas');

        $this->assertDatabaseCount('multas', 0);
    }

    public function test_devolucion_atrasada_genera_multa_pendiente_con_monto_correcto(): void
    {
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $prestamo = $this->prestamoLibro(['fecha_devolucion' => now()->subDays(5)]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $montoEsperado = 5 * config('multas.monto_dia');

        $response->assertStatus(200)
            ->assertJsonPath('multas.0.monto', $montoEsperado)
            ->assertJsonPath('multas.0.estado', 'pendiente')
            ->assertJsonPath('multas.0.tipo', 'atraso');

        $this->assertDatabaseHas('multas', [
            'prestamo_id' => $prestamo->id,
            'usuario_id' => $prestamo->usuario_id,
            'tipo' => 'atraso',
            'monto' => $montoEsperado,
            'estado' => 'pendiente',
            'dias_atraso' => 5,
            'origen' => 'sistema',
            'registrada_por_staff_id' => $staff->id,
        ]);
    }

    public function test_multa_no_se_prorratea_por_fraccion_de_dia(): void
    {
        // Regresión: Carbon 3 (Laravel 12) devuelve diffInDays() como float con fracción
        // de día, así que una diferencia de p.ej. 4 días y 2 horas no debe cobrarse como
        // 4.09 días — el monto siempre debe ser un múltiplo exacto de monto_dia.
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = $this->prestamoLibro(['fecha_devolucion' => now()->subDays(4)->subHours(2)]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")
            ->assertStatus(200)
            ->assertJsonPath('multas.0.monto', 4 * config('multas.monto_dia'));
    }

    public function test_multa_respeta_el_tope_maximo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        // Días de atraso suficientes para superar el tope sea cual sea la tarifa
        // diaria configurada (evita que el test dependa de un monto_dia hardcodeado).
        $diasParaSuperarTope = (int) ceil(config('multas.tope_maximo') / config('multas.monto_dia')) + 10;

        $prestamo = $this->prestamoLibro(['fecha_devolucion' => now()->subDays($diasParaSuperarTope)]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")
            ->assertStatus(200)
            ->assertJsonPath('multas.0.monto', config('multas.tope_maximo'));
    }

    public function test_prestamo_de_equipo_nunca_genera_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = $this->prestamoLibro(['tipo_item' => 'audifonos', 'fecha_devolucion' => null]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")
            ->assertStatus(200)
            ->assertJsonCount(0, 'multas');
    }

    public function test_devolver_dos_veces_no_duplica_la_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = $this->prestamoLibro(['fecha_devolucion' => now()->subDays(3)]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(200);
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertStatus(409);

        $this->assertSame(1, Multa::where('prestamo_id', $prestamo->id)->count());
    }

    public function test_listado_de_prestamos_incluye_sus_multas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = $this->prestamoLibro(['estado' => 'devuelto']);
        Multa::factory()->create(['usuario_id' => $prestamo->usuario_id, 'prestamo_id' => $prestamo->id, 'monto' => 1500]);

        $this->getJson("/api/prestamos?usuario_id={$prestamo->usuario_id}")
            ->assertStatus(200)
            ->assertJsonPath('0.multas.0.monto', 1500);
    }
}
