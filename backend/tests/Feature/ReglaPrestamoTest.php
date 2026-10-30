<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\ReglaPrestamo;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Services\ReglaPrestamoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Reglas de préstamo por tipo de usuario, tipo de material y ubicación. */
class ReglaPrestamoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function copia(?TipoMaterial $tipo = null, ?Ubicacion $ubicacion = null): Ejemplar
    {
        return Ejemplar::factory()
            ->for(Libro::factory()->state(['tipo_material_id' => $tipo?->id]))
            ->create(['ubicacion_id' => $ubicacion?->id]);
    }

    private function prestar(Usuario $usuario, Ejemplar $ejemplar, string $devolucion = '2026-07-15')
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'tipo_item' => 'libro',
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => '2026-07-08',
            'fecha_devolucion' => $devolucion,
        ]);
    }

    public function test_gana_la_regla_mas_especifica_y_en_empate_manda_el_material_luego_la_ubicacion(): void
    {
        $revista = TipoMaterial::factory()->create();
        $hemeroteca = Ubicacion::factory()->create();
        $servicio = app(ReglaPrestamoService::class);

        $general = ReglaPrestamo::create(['dias_prestamo' => 7]);
        $this->assertSame($general->id, $servicio->reglaPara('estudiante', $revista->id, $hemeroteca->id)->id);

        // Empate a un criterio: el material gana al tipo de usuario.
        $soloEstudiante = ReglaPrestamo::create(['tipo_usuario' => 'estudiante', 'dias_prestamo' => 10]);
        $soloRevista = ReglaPrestamo::create(['tipo_material_id' => $revista->id, 'prestable' => false]);
        $this->assertSame($soloRevista->id, $servicio->reglaPara('estudiante', $revista->id, $hemeroteca->id)->id);

        // Dos criterios ganan a uno.
        $estudianteYRevista = ReglaPrestamo::create(['tipo_usuario' => 'estudiante', 'tipo_material_id' => $revista->id, 'dias_prestamo' => 3]);
        $this->assertSame($estudianteYRevista->id, $servicio->reglaPara('estudiante', $revista->id, $hemeroteca->id)->id);

        // Empate a dos: la ubicación pesa más que el tipo de usuario.
        $revistaYHemeroteca = ReglaPrestamo::create(['tipo_material_id' => $revista->id, 'ubicacion_id' => $hemeroteca->id, 'dias_prestamo' => 2]);
        $this->assertSame($revistaYHemeroteca->id, $servicio->reglaPara('estudiante', $revista->id, $hemeroteca->id)->id);

        // Lo que no calza no cuenta.
        $this->assertSame($estudianteYRevista->id, $servicio->reglaPara('estudiante', $revista->id, null)->id);
        $this->assertSame($soloRevista->id, $servicio->reglaPara('docente', $revista->id, null)->id);
        $this->assertSame($soloEstudiante->id, $servicio->reglaPara('estudiante', null, $hemeroteca->id)->id);
        $this->assertSame($general->id, $servicio->reglaPara('docente', null, $hemeroteca->id)->id);
    }

    public function test_sin_reglas_todo_sigue_como_antes(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00');
        $copia = $this->copia();
        $usuario = Usuario::factory()->create();

        $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => $usuario->id, 'codigo_barras' => $copia->codigo_barras]))
            ->assertOk()
            ->assertJsonPath('regla', null)
            ->assertJsonPath('prestable', true)
            ->assertJsonPath('max_ejemplares', 1)
            ->assertJsonPath('fecha_devolucion', null)
            ->assertJsonPath('multa_por_dia', config('multas.monto_dia'))
            ->assertJsonPath('tope_multa', config('multas.tope_maximo'));

        $this->prestar($usuario, $copia)->assertCreated();
        $this->prestar($usuario, $this->copia())->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario ya tiene un libro prestado sin devolver — debe devolverlo antes de llevarse otro.');
    }

    public function test_la_regla_propone_la_fecha_de_devolucion_corrida_al_siguiente_dia_con_atencion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00'); // miércoles
        ReglaPrestamo::create(['tipo_usuario' => 'docente', 'dias_prestamo' => 4, 'max_ejemplares' => 5]);
        $copia = $this->copia();
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        // Miércoles + 4 = domingo, cerrado: se propone el lunes.
        $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => $docente->id, 'codigo_barras' => $copia->codigo_barras]))
            ->assertOk()
            ->assertJsonPath('regla.tipo_usuario', 'docente')
            ->assertJsonPath('dias_prestamo', 4)
            ->assertJsonPath('max_ejemplares', 5)
            ->assertJsonPath('fecha_devolucion', '2026-07-13')
            ->assertJsonPath('motivo_rechazo', null);

        // Un estudiante no calza con la regla de docentes.
        $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => Usuario::factory()->create()->id, 'codigo_barras' => $copia->codigo_barras]))
            ->assertJsonPath('regla', null);
    }

    public function test_rechaza_el_prestamo_si_la_regla_dice_que_no_es_prestable(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00');
        $referencia = Ubicacion::factory()->create(['nombre' => 'Referencia']);
        ReglaPrestamo::create(['ubicacion_id' => $referencia->id, 'prestable' => false]);

        $this->prestar(Usuario::factory()->create(), $this->copia(ubicacion: $referencia))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Solo uso en sala: este material no se presta a domicilio (Todos los usuarios · todo material · Referencia).');
        $this->assertDatabaseCount('prestamos', 0);

        // Otra ubicación no tiene esa regla.
        $this->prestar(Usuario::factory()->create(), $this->copia())->assertCreated();
    }

    public function test_respeta_el_maximo_de_ejemplares_de_la_regla(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00');
        ReglaPrestamo::create(['tipo_usuario' => 'docente', 'dias_prestamo' => 14, 'max_ejemplares' => 2]);
        $docente = Usuario::factory()->create(['tipo' => 'docente']);

        $this->prestar($docente, $this->copia())->assertCreated();
        $this->prestar($docente, $this->copia())->assertCreated();
        $this->prestar($docente, $this->copia())->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario ya tiene 2 libros prestados sin devolver: el máximo a la vez es 2. Debe devolver uno antes de llevarse otro.');
        $this->assertSame(2, Prestamo::where('usuario_id', $docente->id)->count());
    }

    public function test_la_multa_usa_la_tarifa_y_el_tope_de_la_regla(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00'); // miércoles
        $tesis = TipoMaterial::factory()->create();
        ReglaPrestamo::create(['tipo_material_id' => $tesis->id, 'dias_prestamo' => 3, 'multa_por_dia' => 500, 'tope_multa' => 1200]);

        // Vencía el lunes 6: martes y miércoles de atraso = 2 días × $500.
        $conRegla = Prestamo::factory()->create(['ejemplar_id' => $this->copia($tesis)->id, 'fecha_devolucion' => '2026-07-06']);
        $this->patchJson("/api/prestamos/{$conRegla->id}/devolver")->assertOk()->assertJsonPath('multas.0.monto', 1000);

        // Vencía el miércoles 1: 5 días con atención (sin el domingo) × $500 = 2.500, tope 1.200.
        $conTope = Prestamo::factory()->create(['ejemplar_id' => $this->copia($tesis)->id, 'fecha_devolucion' => '2026-07-01']);
        $this->patchJson("/api/prestamos/{$conTope->id}/devolver")->assertOk()->assertJsonPath('multas.0.monto', 1200);

        // Otro material sigue con config/multas.php.
        $sinRegla = Prestamo::factory()->create(['ejemplar_id' => $this->copia()->id, 'fecha_devolucion' => '2026-07-06']);
        $this->patchJson("/api/prestamos/{$sinRegla->id}/devolver")->assertOk()->assertJsonPath('multas.0.monto', 2 * config('multas.monto_dia'));
    }

    public function test_la_regla_que_no_fija_tarifa_usa_la_de_config(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Carbon::setTestNow('2026-07-08 10:00:00');
        ReglaPrestamo::create(['dias_prestamo' => 7]);

        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $this->copia()->id, 'fecha_devolucion' => '2026-07-06']);
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk()->assertJsonPath('multas.0.monto', 2 * config('multas.monto_dia'));
    }

    public function test_administracion_crea_edita_y_elimina_reglas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $revista = TipoMaterial::factory()->create(['nombre' => 'Revista científica']);

        $id = $this->postJson('/api/reglas-prestamo', [
            'tipo_usuario' => 'estudiante',
            'tipo_material_id' => $revista->id,
            'dias_prestamo' => 3,
            'max_ejemplares' => 2,
            'multa_por_dia' => 100,
            'tope_multa' => 3000,
            'prestable' => true,
        ])->assertCreated()
            ->assertJsonPath('tipo_material.nombre', 'Revista científica')
            ->json('id');

        $this->postJson('/api/reglas-prestamo', ['tipo_usuario' => 'estudiante', 'tipo_material_id' => $revista->id, 'dias_prestamo' => 5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya hay una regla para ese tipo de usuario, tipo de material y ubicación: edítala en vez de crear otra.');

        $this->putJson("/api/reglas-prestamo/{$id}", ['tipo_usuario' => 'estudiante', 'tipo_material_id' => $revista->id, 'dias_prestamo' => 5, 'prestable' => false])
            ->assertOk()
            ->assertJsonPath('dias_prestamo', 5)
            ->assertJsonPath('prestable', false);

        $this->getJson('/api/reglas-prestamo')->assertOk()
            // La otra es la de los juegos (solo uso en sala), que viene con la instalación.
            ->assertJsonCount(2, 'reglas')
            ->assertJsonPath('sin_regla.max_ejemplares', 1);

        $this->deleteJson("/api/reglas-prestamo/{$id}")->assertNoContent();
        $this->assertDatabaseCount('reglas_prestamo', 1);
        $this->assertDatabaseHas('bitacora', ['accion' => 'configuracion.eliminar_regla_prestamo']);
    }

    public function test_sin_el_permiso_de_catalogos_no_se_administran_las_reglas(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/reglas-prestamo')->assertForbidden();
        $this->postJson('/api/reglas-prestamo', ['dias_prestamo' => 7])->assertForbidden();
    }

    public function test_la_base_no_admite_dos_reglas_para_todos(): void
    {
        ReglaPrestamo::create(['dias_prestamo' => 7]);

        $this->expectException(QueryException::class);
        ReglaPrestamo::create(['dias_prestamo' => 14]);
    }

    public function test_la_base_rechaza_un_tipo_de_usuario_desconocido(): void
    {
        $this->expectException(QueryException::class);
        ReglaPrestamo::create(['tipo_usuario' => 'visitante', 'dias_prestamo' => 7]);
    }
}
