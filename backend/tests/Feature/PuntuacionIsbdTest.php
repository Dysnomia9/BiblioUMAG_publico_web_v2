<?php

namespace Tests\Feature;

use App\Models\Libro;
use App\Models\Staff;
use App\Support\PuntuacionIsbd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La descripción física (300) y la edición (250) quedan como lo migrado de Horizon; la serie (490), sin paréntesis. */
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

    public function test_la_serie_se_guarda_sin_parentesis_y_la_ficha_los_pone(): void
    {
        $casos = [
            'Serie Harry Potter ; 1' => 'Serie Harry Potter ; 1',
            '(Serie Harry Potter ; 1)' => 'Serie Harry Potter ; 1',
            '(HARRY POTTER)' => 'HARRY POTTER',
            '(La Ley / Thomson Reuters' => 'La Ley / Thomson Reuters',
            'Serie (2a. época) ; 3' => 'Serie (2a. época) ; 3',   // los de adentro se quedan
            "(Notas de matemática ; 85)\n\n North-Holland mathematics studies ; 67 " => "Notas de matemática ; 85\nNorth-Holland mathematics studies ; 67",
            ' ( ) ' => null,
            null => null,
        ];
        foreach ($casos as $entrada => $esperado) {
            $this->assertSame($esperado, PuntuacionIsbd::serie($entrada === '' ? null : (string) $entrada), "«{$entrada}»");
        }

        Sanctum::actingAs(Staff::factory()->create());
        $libro = Libro::factory()->create();
        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'serie' => '(Serie Harry Potter ; 1)'])->assertOk();
        $this->assertSame('Serie Harry Potter ; 1', $libro->fresh()->serie);
    }
}
