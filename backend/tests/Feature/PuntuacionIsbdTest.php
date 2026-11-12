<?php

namespace Tests\Feature;

use App\Models\Libro;
use App\Models\Staff;
use App\Support\PuntuacionIsbd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La descripción física (300) y la edición (250) quedan como lo migrado de Horizon. */
class PuntuacionIsbdTest extends TestCase
{
    use RefreshDatabase;

    public function test_espacios_y_punto_final(): void
    {
        $casos = [
            '350 p. : il. ; 24 cm' => '350 p. : il. ; 24 cm.',
            '350 p.:il.;24 cm' => '350 p. : il. ; 24 cm.',
            '  350   p. :  il.  ;24 cm. ' => '350 p. : il. ; 24 cm.',
            '51 h.+1 disco compacto' => '51 h. + 1 disco compacto.',
            'xvii, 267 p. : il.' => 'xvii, 267 p. : il.',
            '1 mapa ; 1:50.000' => '1 mapa ; 1:50.000.',      // los dos puntos entre dígitos no se tocan
            '2a. ed. revisada' => '2a. ed. revisada.',
            '¿Edición pirata?' => '¿Edición pirata?',
            '350 p ; ' => '350 p.',                           // sin signo colgando al final
            '   ' => null,
            null => null,
        ];
        foreach ($casos as $entrada => $esperado) {
            $this->assertSame($esperado, PuntuacionIsbd::normalizar($entrada === '' ? null : (string) $entrada), "«{$entrada}»");
        }
    }

    public function test_se_aplica_al_crear_y_al_editar_desde_catalogacion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create(['descripcion_fisica' => '350 p.:il.;24 cm', 'edicion' => '3rd ed']);

        $this->assertSame('350 p. : il. ; 24 cm.', $libro->fresh()->descripcion_fisica);
        $this->assertSame('3rd ed.', $libro->fresh()->edicion);

        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'descripcion_fisica' => 'xvii, 267 p : il', 'edicion' => ''])->assertOk();
        $this->assertSame('xvii, 267 p : il.', $libro->fresh()->descripcion_fisica);
        $this->assertNull($libro->fresh()->edicion);
    }
}
