<?php

namespace Tests\Feature;

use App\Models\Bitacora as Registro;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Bitacora;
use App\Support\FichaBibliografica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Los casos fijos de mockup:datos que usa docs/recorrido-presentacion.md. */
class EscenarioDemostracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_datos_de_prueba_se_regeneran_aunque_ya_se_haya_usado_el_sistema(): void
    {
        $this->artisan('mockup:datos')->assertSuccessful();

        // Lo que hacen el personal y los usuarios queda en la bitácora, que apunta a sus cuentas.
        Bitacora::registrar('personal.editar', 'Editó una cuenta', actor: Staff::firstOrFail());
        Bitacora::registrar('salas.reservar', 'Reservó desde el portal', actor: Usuario::firstOrFail());

        $this->artisan('mockup:datos', ['--fresh' => true])->assertSuccessful();

        $this->assertSame(0, Registro::count());
        $this->assertGreaterThan(0, Staff::count());
    }

    public function test_los_datos_de_prueba_traen_el_escenario_de_la_presentacion(): void
    {
        $this->artisan('mockup:datos')->assertSuccessful();

        $persona = fn (string $rut) => Usuario::where('rut', $rut)->firstOrFail();

        $valentina = $persona('11.111.111-1');
        $this->assertSame(0, Prestamo::where('usuario_id', $valentina->id)->count());
        $this->assertSame(0, Multa::pendientes()->where('usuario_id', $valentina->id)->count());

        $this->assertTrue(Prestamo::vencidos()->where('usuario_id', $persona('22.222.222-2')->id)->exists());
        $this->assertSame(3500, Multa::pendientes()->where('usuario_id', $persona('33.333.333-3')->id)->sole()->monto);
        $this->assertSame('pendiente', ReservaLibro::where('usuario_id', $persona('44.444.444-4')->id)->sole()->estado);
        $this->assertSame('en_cola', ReservaLibro::where('usuario_id', $persona('55.555.555-5')->id)->sole()->estado);
        $persona('66.666.666-6');

        foreach ([
            'Flora de la Patagonia austral' => 'libro',
            'Sistema web para la gestión de la biblioteca de la Universidad de Magallanes' => 'trabajo_titulacion',
            'Memorice de la fauna magallánica' => 'juego_educativo',
            'Cuadernos de Estudios Australes' => 'publicacion_seriada',
        ] as $titulo => $plantilla) {
            $libro = Libro::where('titulo', $titulo)->with(['tipoMaterial', 'autores', 'categorias', 'catalogadoPor'])->firstOrFail();
            $this->assertSame($plantilla, FichaBibliografica::plantillaDe($libro));
            $this->assertNotEmpty(collect(FichaBibliografica::ficha($libro, conInternos: true))->where('interno', true));
        }

        $this->assertTrue(Equipo::where('codigo_barras', '7510000000011')->sole()->disponible);
        $this->assertTrue(Equipo::where('codigo_barras', '7510000000012')->sole()->disponible);
    }
}
