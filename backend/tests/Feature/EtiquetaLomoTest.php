<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Código de lomo: segundo código de barras de 8 dígitos de cada copia, para la etiqueta del lomo. */
class EtiquetaLomoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_copia_nueva_recibe_su_codigo_de_lomo_de_8_digitos_distinto(): void
    {
        $a = Ejemplar::factory()->create()->refresh();
        $b = Ejemplar::factory()->create()->refresh();

        $this->assertMatchesRegularExpression('/^\d{8}$/', $a->codigo_lomo);
        $this->assertNotSame($a->codigo_lomo, $b->codigo_lomo);
        $this->assertNotSame($a->codigo_barras, $a->codigo_lomo);
    }

    public function test_el_codigo_de_lomo_no_se_puede_repetir(): void
    {
        $a = Ejemplar::factory()->create()->refresh();

        $this->expectException(QueryException::class);
        Ejemplar::factory()->create()->forceFill(['codigo_lomo' => $a->codigo_lomo])->save();
    }

    public function test_agregar_una_copia_devuelve_su_codigo_de_lomo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();

        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000009999'])
            ->assertCreated()
            ->assertJsonPath('codigo_barras', '30000000009999');

        $this->assertMatchesRegularExpression('/^\d{8}$/', Ejemplar::sole()->codigo_lomo);
    }

    public function test_escanear_el_codigo_de_lomo_encuentra_la_copia_y_sirve_para_prestar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = Ejemplar::factory()->create(['estado_proceso' => 'en_estante'])->refresh();
        $usuario = Usuario::factory()->create();

        $this->getJson("/api/ejemplares/{$ejemplar->codigo_lomo}")->assertOk()->assertJsonPath('id', $ejemplar->id);

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_lomo,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $this->fechaConAtencion(7),
        ])->assertCreated()->assertJsonPath('codigo_barras', $ejemplar->codigo_barras);
    }

    public function test_un_codigo_de_barras_nuevo_no_puede_ser_el_codigo_de_lomo_de_otra_copia(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $existente = Ejemplar::factory()->create()->refresh();

        $this->postJson('/api/ejemplares', ['libro_id' => $existente->libro_id, 'codigo_barras' => $existente->codigo_lomo])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo_barras');
    }

    public function test_los_datos_de_las_etiquetas_por_codigo_y_por_titulo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['titulo' => 'Historia de Magallanes', 'clasificacion' => '998 E56 2026']);
        $copia1 = Ejemplar::factory()->create(['libro_id' => $libro->id, 'numero_copia' => 1])->refresh();
        Ejemplar::factory()->create(['libro_id' => $libro->id, 'numero_copia' => 2]);
        $otra = Ejemplar::factory()->create()->refresh();

        $this->postJson('/api/ejemplares/etiquetas-lomo', ['codigos' => [$otra->codigo_barras, 'NO-EXISTE']])
            ->assertOk()
            ->assertJsonCount(1, 'etiquetas')
            ->assertJsonPath('etiquetas.0.codigo_lomo', $otra->codigo_lomo)
            ->assertJsonPath('no_encontrados', ['NO-EXISTE']);

        $this->postJson('/api/ejemplares/etiquetas-lomo', ['codigos' => [$copia1->codigo_lomo], 'libro_ids' => [$libro->id]])
            ->assertOk()
            ->assertJsonCount(2, 'etiquetas')
            ->assertJsonPath('etiquetas.0.clasificacion', '998 E56 2026')
            ->assertJsonPath('etiquetas.1.numero_copia', 2);
    }

    public function test_sin_permiso_de_catalogar_no_se_generan_etiquetas(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->postJson('/api/ejemplares/etiquetas-lomo', ['codigos' => ['30000000000901']])->assertForbidden();
    }
}
