<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\ReservaLibroService;
use App\Support\TomoEjemplar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TomoEjemplarTest extends TestCase
{
    use RefreshDatabase;

    public function test_lee_el_tomo_y_la_copia_como_los_escribe_horizon(): void
    {
        $this->assertSame(['v.1', 2], TomoEjemplar::desdeEtiqueta('v.1 c.2'));
        $this->assertSame(['v.1', 3], TomoEjemplar::desdeEtiqueta('v.1-c.3'));
        $this->assertSame(['t.4', null], TomoEjemplar::desdeEtiqueta('t.4'));
        $this->assertSame(['t.2', null], TomoEjemplar::desdeEtiqueta('Tomo 2'));
        $this->assertSame([null, null], TomoEjemplar::desdeEtiqueta('No. 367 (Ene., 2014)'));
    }

    public function test_al_agregar_una_copia_de_un_tomo_se_calcula_la_siguiente_de_ese_tomo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create();
        // Como vino de Horizon: «t.1» sola (cuenta como la 1) y «t.2 c.1»
        Ejemplar::factory()->for($libro)->create(['volumen' => 't.1', 'tomo' => 't.1', 'copia_tomo' => null]);
        Ejemplar::factory()->for($libro)->create(['volumen' => 't.2 c.1', 'tomo' => 't.2', 'copia_tomo' => 1]);

        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000201', 'tomo' => 'Tomo 1'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.1 c.2')->assertJsonPath('copia_tomo', 2);
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000202', 'tomo' => 't.1'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.1 c.3');
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000203', 'tomo' => 't.2'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.2 c.2');
    }

    public function test_corregir_el_volumen_a_mano_actualiza_el_tomo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $copia = Ejemplar::factory()->create(['volumen' => 't.1', 'tomo' => 't.1']);

        $this->patchJson("/api/ejemplares/{$copia->id}", ['volumen' => 't.3 c.2'])->assertOk();
        $this->assertSame(['t.3', 2], [$copia->fresh()->tomo, $copia->fresh()->copia_tomo]);
    }

    public function test_en_el_portal_se_reserva_el_tomo_elegido_y_la_fila_espera_ese_tomo(): void
    {
        $libro = Libro::factory()->create();
        $t1 = Ejemplar::factory()->for($libro)->create(['tomo' => 't.1', 'volumen' => 't.1', 'disponible' => false]);
        Ejemplar::factory()->for($libro)->create(['tomo' => 't.2', 'volumen' => 't.2', 'disponible' => true]);

        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $fila = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $libro->id);
        $this->assertSame([['tomo' => 't.1', 'copias' => 1, 'disponibles' => 0], ['tomo' => 't.2', 'copias' => 1, 'disponibles' => 1]], $fila['tomos']);

        // Pide el t.1 (prestado): queda en la fila de ese tomo, aunque el t.2 esté libre.
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $libro->id, 'tomo' => 't.1'])
            ->assertStatus(201)->assertJsonPath('estado', 'en_cola')->assertJsonPath('tomo', 't.1');

        // Cuando vuelve una copia del t.2 no se le aparta; cuando vuelve la del t.1, sí.
        $servicio = app(ReservaLibroService::class);
        $otraDelT2 = Ejemplar::factory()->for($libro)->create(['tomo' => 't.2', 'disponible' => false]);
        $this->assertNull($servicio->liberarLibro($otraDelT2));
        $apartada = $servicio->liberarLibro($t1);
        $this->assertSame($t1->id, $apartada?->ejemplar_id);
        $this->assertSame('pendiente', ReservaLibro::first()->estado);
    }
}
