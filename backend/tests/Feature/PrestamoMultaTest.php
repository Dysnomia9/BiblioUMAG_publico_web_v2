<?php

namespace Tests\Feature;

use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestamoMultaTest extends TestCase
{
    use RefreshDatabase;

    public function test_devolucion_a_tiempo_no_genera_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->addDays(2),
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $response->assertStatus(200)
            ->assertJsonPath('multa_monto', null)
            ->assertJsonPath('multa_estado', null);
    }

    public function test_devolucion_atrasada_genera_multa_pendiente_con_monto_correcto(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->subDays(5),
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $montoEsperado = 5 * config('multas.monto_dia');

        $response->assertStatus(200)
            ->assertJsonPath('multa_monto', $montoEsperado)
            ->assertJsonPath('multa_estado', 'pendiente');

        $this->assertDatabaseHas('prestamos', [
            'id' => $prestamo->id,
            'multa_monto' => $montoEsperado,
            'multa_estado' => 'pendiente',
        ]);
    }

    public function test_multa_no_se_prorratea_por_fraccion_de_dia(): void
    {
        // Regresión: Carbon 3 (Laravel 12) devuelve diffInDays() como float con fracción
        // de día, así que una diferencia de p.ej. 4 días y 2 horas no debe cobrarse como
        // 4.09 días — el monto siempre debe ser un múltiplo exacto de monto_dia.
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->subDays(4)->subHours(2),
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $montoEsperado = 4 * config('multas.monto_dia');

        $response->assertStatus(200)->assertJsonPath('multa_monto', $montoEsperado);
    }

    public function test_multa_respeta_el_tope_maximo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        // Días de atraso suficientes para superar el tope sea cual sea la tarifa
        // diaria configurada (evita que el test dependa de un monto_dia hardcodeado).
        $diasParaSuperarTope = (int) ceil(config('multas.tope_maximo') / config('multas.monto_dia')) + 10;

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'fecha_devolucion' => now()->subDays($diasParaSuperarTope),
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $response->assertStatus(200)
            ->assertJsonPath('multa_monto', config('multas.tope_maximo'));
    }

    public function test_prestamo_de_equipo_nunca_genera_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'audifonos',
            'fecha_devolucion' => null,
            'estado' => 'activo',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/devolver");

        $response->assertStatus(200)
            ->assertJsonPath('multa_monto', null)
            ->assertJsonPath('multa_estado', null);
    }

    public function test_marcar_multa_como_pagada(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Juan Pérez']);
        Sanctum::actingAs($staff);

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'tipo_item' => 'libro',
            'estado' => 'devuelto',
            'multa_monto' => 1500,
            'multa_estado' => 'pendiente',
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/multa/pagar");

        $response->assertStatus(200)
            ->assertJsonPath('multa_estado', 'pagada')
            ->assertJsonPath('multa_pagada_por', 'Juan Pérez')
            ->assertJsonPath('multa_pagada_por_staff_id', $staff->id);

        $prestamo->refresh();
        $this->assertNotNull($prestamo->multa_pagada_en);
    }

    public function test_pagar_multa_sin_pendiente_devuelve_409(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $prestamo = Prestamo::factory()->create([
            'usuario_id' => Usuario::factory()->create()->id,
            'estado' => 'devuelto',
            'multa_monto' => null,
            'multa_estado' => null,
        ]);

        $response = $this->patchJson("/api/prestamos/{$prestamo->id}/multa/pagar");

        $response->assertStatus(409);
    }
}
