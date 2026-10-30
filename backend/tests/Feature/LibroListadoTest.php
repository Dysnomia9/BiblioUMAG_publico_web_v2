<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Lo que el listado de libros y el historial necesitan de la API. */
class LibroListadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
    }

    public function test_cada_copia_dice_si_esta_prestada_o_reservada(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Bases de Datos']);
        $prestada = Ejemplar::factory()->for($libro)->create(['numero_copia' => 1, 'disponible' => false]);
        $reservada = Ejemplar::factory()->for($libro)->create(['numero_copia' => 2, 'disponible' => false]);
        $libre = Ejemplar::factory()->for($libro)->create(['numero_copia' => 3]);
        Prestamo::factory()->create(['ejemplar_id' => $prestada->id]);
        Prestamo::factory()->create(['ejemplar_id' => $libre->id, 'estado' => 'devuelto', 'fecha_devolucion_real' => now()]);
        ReservaLibro::factory()->create(['libro_id' => $libro->id, 'ejemplar_id' => $reservada->id]);

        $copias = collect($this->getJson('/api/libros')->assertOk()->json('data.0.ejemplares'))->keyBy('numero_copia');

        $this->assertTrue($copias[1]['prestado']);
        $this->assertFalse($copias[1]['reservado']);
        $this->assertTrue($copias[2]['reservado']);
        $this->assertFalse($copias[3]['prestado']);
        $this->assertFalse($copias[3]['reservado']);
    }

    public function test_titulo_y_copias_traen_su_ingreso_a_horizon_y_a_biblioumag(): void
    {
        $libro = Libro::factory()->create();
        $libro->forceFill(['fecha_ingreso_horizon' => '1995-07-06'])->save();
        $copia = Ejemplar::factory()->for($libro)->create();
        $copia->forceFill(['fecha_ingreso_horizon' => '1995-08-23'])->save();
        // Lo catalogado en BiblioUMAG no tiene fecha de Horizon
        Ejemplar::factory()->for($libro)->create(['numero_copia' => 2]);

        $respuesta = $this->getJson('/api/libros')->assertOk()
            ->assertJsonPath('data.0.fecha_ingreso_horizon', '1995-07-06')
            ->assertJsonPath('data.0.ejemplares.0.fecha_ingreso_horizon', '1995-08-23')
            ->assertJsonPath('data.0.ejemplares.1.fecha_ingreso_horizon', null);
        $this->assertNotNull($respuesta->json('data.0.created_at'));
        $this->assertNotNull($respuesta->json('data.0.ejemplares.0.created_at'));
    }

    public function test_se_busca_por_clasificacion_dewey(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Bases de Datos', 'clasificacion' => '005.98 A664b 2012']);
        Libro::factory()->create(['titulo' => 'Otro', 'clasificacion' => '510.34 J802c 2012']);

        $ids = collect($this->getJson('/api/libros?q=005.98')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$libro->id], $ids);
    }

    public function test_el_historial_trae_vencimiento_y_dewey(): void
    {
        $libro = Libro::factory()->create(['titulo' => 'Bases de Datos', 'clasificacion' => '005.98 A664b 2012', 'isbn' => '9792406334599']);
        $copia = Ejemplar::factory()->for($libro)->create();
        Prestamo::factory()->create([
            'ejemplar_id' => $copia->id,
            'fecha_prestamo' => '2026-09-10 10:00:00',
            'fecha_devolucion' => '2026-09-17 10:00:00',
            'fecha_devolucion_real' => '2026-09-19 12:00:00',
            'estado' => 'devuelto',
        ]);

        $this->getJson('/api/libros/historial?q=9792406334599')->assertOk()
            ->assertJsonPath('data.0.libro.clasificacion', '005.98 A664b 2012')
            ->assertJsonPath('data.0.total_prestamos', 1);

        $detalle = $this->getJson("/api/libros/{$libro->id}/historial")->assertOk();
        $this->assertStringStartsWith('2026-09-17', $detalle->json('ejemplares.0.prestamos.0.fecha_devolucion'));
    }
}
