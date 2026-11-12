<?php

namespace Tests\Feature;

use App\Models\DiaSinAtencion;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\PrestamoInterbibliotecario;
use App\Models\ReglaPrestamo;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Préstamo interbibliotecario: el material que otra biblioteca le presta a la nuestra se
 * presta a los usuarios hasta un día hábil antes de su fecha límite y, al devolverlo a su
 * biblioteca, sale del catálogo sin perder sus préstamos (que cuentan en los reportes).
 */
class PrestamoInterbibliotecarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una copia en estante de otra biblioteca, que tiene que volver a ella el $limite. */
    private function deOtraBiblioteca(string $limite = '2026-11-16', string $ingreso = '2026-11-02'): Ejemplar
    {
        $ejemplar = Ejemplar::factory()->for(Libro::factory()->create(['titulo' => 'Química orgánica']))->create();
        PrestamoInterbibliotecario::create([
            'ejemplar_id' => $ejemplar->id,
            'biblioteca' => 'Biblioteca Central U. de Chile',
            'fecha_ingreso' => $ingreso,
            'fecha_limite' => $limite,
        ]);

        return $ejemplar;
    }

    private function prestar(Ejemplar $ejemplar, string $devolucion, array $extra = [])
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => Usuario::factory()->create()->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $devolucion,
            ...$extra,
        ]);
    }

    public function test_el_tope_es_el_dia_habil_anterior_sin_sabados_ni_dias_sin_atencion(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        // Vuelve el lunes 16: el sábado 14 la biblioteca abre, pero no es día hábil.
        $this->getJson('/api/prestamos-interbibliotecarios/fecha-tope?fecha_limite=2026-11-16')
            ->assertOk()->assertJsonPath('fecha_tope', '2026-11-13');

        // Un feriado el viernes lo corre al jueves.
        DiaSinAtencion::create(['fecha' => '2026-11-13', 'motivo' => 'Feriado']);
        $this->getJson('/api/prestamos-interbibliotecarios/fecha-tope?fecha_limite=2026-11-16')
            ->assertOk()->assertJsonPath('fecha_tope', '2026-11-12');
    }

    public function test_registra_un_titulo_nuevo_con_su_copia_por_colocar(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00'); // miércoles
        $staff = Staff::factory()->create();
        Sanctum::actingAs($staff);

        $respuesta = $this->postJson('/api/prestamos-interbibliotecarios', [
            'titulo' => 'Fisiología médica',
            'titulos_alternativos' => ['Guyton y Hall', '  Textbook of medical physiology ', ''],
            'autores' => ['Guyton, Arthur C.', 'Hall, John E.'],
            'isbn' => '9788491130246',
            'codigo_barras' => '30000009990001',
            'biblioteca' => 'Biblioteca Central U. de Chile',
            'fecha_ingreso' => '2026-11-04',
            'fecha_limite' => '2026-11-16',
            'nota' => 'Lo pidió la Escuela de Medicina',
        ])->assertCreated()
            ->assertJsonPath('biblioteca', 'Biblioteca Central U. de Chile')
            ->assertJsonPath('fecha_limite', '2026-11-16')
            ->assertJsonPath('fecha_tope', '2026-11-13')
            ->assertJsonPath('vencido', false)
            ->assertJsonPath('ejemplar.libro.titulo', 'Fisiología médica')
            ->assertJsonPath('registrado_por.nombre', $staff->nombre);

        $ejemplar = Ejemplar::find($respuesta->json('ejemplar_id'));
        $this->assertSame('por_colocar', $ejemplar->estado_proceso);
        $this->assertTrue($ejemplar->disponible);
        $this->assertSame(0, $ejemplar->numero_copia); // título nuevo: su primera copia no lleva «c.»
        $this->assertSame(['Guyton, Arthur C.', 'Hall, John E.'], $ejemplar->libro->autores->pluck('nombre')->all());
        $this->assertSame(['Guyton y Hall', 'Textbook of medical physiology'], explode("\n", $ejemplar->libro->titulo_alternativo));
        $this->assertSame($staff->id, $ejemplar->libro->catalogado_por_staff_id);
        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.recibir_interbibliotecario', 'entidad_id' => $respuesta->json('id')]);
    }

    public function test_una_copia_de_un_titulo_del_catalogo_atiende_su_lista_de_espera_al_pasar_al_estante(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $propia = Ejemplar::factory()->create(['disponible' => false]);
        $esperando = Usuario::factory()->create();
        $enCola = ReservaLibro::factory()->create(['usuario_id' => $esperando->id, 'libro_id' => $propia->libro_id, 'ejemplar_id' => null, 'estado' => 'en_cola', 'fecha_retiro' => null]);

        $respuesta = $this->postJson('/api/prestamos-interbibliotecarios', [
            'libro_id' => $propia->libro_id,
            'codigo_barras' => '30000009990002',
            'biblioteca' => 'Biblioteca UACh',
            'fecha_ingreso' => '2026-11-04',
            'fecha_limite' => '2026-11-30',
        ])->assertCreated()->assertJsonPath('ejemplar.numero_copia', 1); // la propia es la 0

        $this->assertDatabaseCount('libros', 1);
        // «Por colocar» todavía no se presta: la fila espera hasta que pase al estante.
        $this->assertDatabaseHas('reservas_libro', ['id' => $enCola->id, 'estado' => 'en_cola']);
        $this->prestar(Ejemplar::find($respuesta->json('ejemplar_id')), '2026-11-10')->assertStatus(409);

        $this->patchJson('/api/ejemplares/'.$respuesta->json('ejemplar_id').'/estado', ['estado_proceso' => 'en_estante'])->assertOk();
        $this->assertDatabaseHas('reservas_libro', ['id' => $enCola->id, 'estado' => 'pendiente', 'ejemplar_id' => $respuesta->json('ejemplar_id')]);
        $this->assertDatabaseHas('ejemplares', ['id' => $respuesta->json('ejemplar_id'), 'disponible' => false]);
    }

    public function test_valida_lo_obligatorio_y_que_la_fecha_limite_sea_posterior_al_ingreso(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $existente = Ejemplar::factory()->create();

        $this->postJson('/api/prestamos-interbibliotecarios', [
            'codigo_barras' => $existente->codigo_barras,
            'fecha_ingreso' => '2026-11-05',
            'fecha_limite' => '2026-11-04',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['titulo', 'codigo_barras', 'biblioteca', 'fecha_ingreso', 'fecha_limite'])
            ->assertJsonPath('errors.titulo.0', 'Elige el título en el catálogo o escribe el título nuevo.')
            ->assertJsonPath('errors.biblioteca.0', 'Indica la biblioteca propietaria.')
            ->assertJsonPath('errors.fecha_ingreso.0', 'La fecha de ingreso no puede ser futura.')
            ->assertJsonPath('errors.fecha_limite.0', 'La fecha límite tiene que ser posterior a la de ingreso.');

        $this->assertDatabaseCount('prestamos_interbibliotecarios', 0);
    }

    public function test_al_prestar_la_devolucion_no_pasa_del_dia_habil_anterior_a_la_fecha_limite(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00'); // miércoles
        Sanctum::actingAs(Staff::factory()->create());
        ReglaPrestamo::create(['dias_prestamo' => 14, 'max_ejemplares' => 3]);
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');

        // La regla propondría el 18; se propone el tope, el viernes 13.
        $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => Usuario::factory()->create()->id, 'codigo_barras' => $ejemplar->codigo_barras]))
            ->assertOk()
            ->assertJsonPath('fecha_devolucion', '2026-11-13')
            ->assertJsonPath('interbibliotecario.biblioteca', 'Biblioteca Central U. de Chile')
            ->assertJsonPath('interbibliotecario.fecha_limite', '2026-11-16')
            ->assertJsonPath('interbibliotecario.fecha_tope', '2026-11-13')
            ->assertJsonPath('motivo_rechazo', null);

        $this->prestar($ejemplar, '2026-11-14')->assertStatus(409)
            ->assertJsonPath('message', 'Es material de préstamo interbibliotecario de Biblioteca Central U. de Chile: se devuelve a más tardar el 13/11/2026, un día hábil antes de que tenga que volver a su biblioteca (16/11/2026).');
        $this->assertDatabaseCount('prestamos', 0);

        $this->prestar($ejemplar, '2026-11-13')->assertCreated();
    }

    public function test_pasado_el_tope_no_se_presta_ni_en_sala_ni_se_reserva(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00'); // sábado: el tope fue el viernes
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');
        $usuario = Usuario::factory()->create();

        $condiciones = $this->getJson('/api/prestamos/condiciones?'.http_build_query(['usuario_id' => $usuario->id, 'codigo_barras' => $ejemplar->codigo_barras]))->assertOk();
        $this->assertStringContainsString('Ya no se presta', $condiciones->json('motivo_rechazo'));
        $this->assertSame($condiciones->json('motivo_rechazo'), $condiciones->json('motivo_rechazo_en_sala'));

        $this->prestar($ejemplar, '2026-11-14', ['en_sala' => true])->assertStatus(409);
        $this->postJson('/api/reservas-libro', ['usuario_id' => $usuario->id, 'codigo_barras' => $ejemplar->codigo_barras])->assertStatus(409);
        $this->assertDatabaseCount('prestamos', 0);
        $this->assertDatabaseCount('reservas_libro', 0);
    }

    public function test_devolver_a_su_biblioteca_saca_la_copia_sin_borrar_sus_prestamos(): void
    {
        Carbon::setTestNow('2026-11-16 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'estado' => 'devuelto', 'fecha_devolucion_real' => '2026-11-10 12:00:00']);
        $esperando = ReservaLibro::factory()->create(['libro_id' => $ejemplar->libro_id, 'ejemplar_id' => null, 'estado' => 'en_cola', 'fecha_retiro' => null]);
        $pib = PrestamoInterbibliotecario::where('ejemplar_id', $ejemplar->id)->first();

        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver", ['nota' => 'Enviado por correo, guía 5544'])
            ->assertOk()
            ->assertJsonPath('devuelto_el', '2026-11-16')
            ->assertJsonPath('total_prestamos', 1)
            ->assertJsonPath('reservas_canceladas', 1);

        $ejemplar->refresh();
        $this->assertSame('devuelto_propietaria', $ejemplar->estado_proceso);
        $this->assertFalse($ejemplar->disponible);
        $this->assertDatabaseHas('prestamos', ['id' => $prestamo->id, 'ejemplar_id' => $ejemplar->id]);
        $this->assertDatabaseHas('ejemplar_estado_historial', ['ejemplar_id' => $ejemplar->id, 'estado_anterior' => 'en_estante', 'estado_nuevo' => 'devuelto_propietaria']);
        $this->assertDatabaseHas('reservas_libro', ['id' => $esperando->id, 'estado' => 'cancelado']);
        $this->assertSame('Enviado por correo, guía 5544', $pib->fresh()->nota);

        // Sale de «En la biblioteca» y queda en «Devueltos».
        $this->getJson('/api/prestamos-interbibliotecarios')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('conteos.devueltos', 1);
        $this->getJson('/api/prestamos-interbibliotecarios?vista=devueltos')->assertOk()->assertJsonPath('data.0.id', $pib->id);

        // Ya devuelta: ni otra vez, ni cambiarle el estado, ni prestarla.
        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver")->assertStatus(409);
        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'en_estante'])->assertStatus(409);
        $this->prestar($ejemplar, '2026-11-17')->assertStatus(409);
    }

    public function test_si_figura_prestada_no_se_devuelve_sin_motivo_ni_confirmar(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');
        $ejemplar->update(['disponible' => false]);
        Prestamo::factory()->create(['ejemplar_id' => $ejemplar->id, 'fecha_devolucion' => '2026-11-13']);
        $pib = PrestamoInterbibliotecario::where('ejemplar_id', $ejemplar->id)->first();

        $this->getJson('/api/prestamos-interbibliotecarios')->assertOk()->assertJsonPath('data.0.prestamo_activo.fecha_devolucion', '2026-11-13');
        // Se busca por el título (sin tildes) o la biblioteca.
        $this->getJson('/api/prestamos-interbibliotecarios?q=quimica')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/prestamos-interbibliotecarios?q=central chile')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/prestamos-interbibliotecarios?q=uach')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver")->assertStatus(409);
        // Sin motivo, o sin escribir CONFIRMAR, tampoco.
        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver", ['confirmacion' => 'CONFIRMAR'])->assertStatus(409);
        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver", ['motivo' => 'Se perdió'])->assertStatus(409);
        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver", ['motivo' => 'Se perdió', 'confirmacion' => 'SI'])->assertStatus(422);
        $this->assertNull($pib->fresh()->devuelto_el);
        $this->assertSame('en_estante', $ejemplar->fresh()->estado_proceso);
    }

    public function test_si_figura_prestada_pero_ya_volvio_el_meson_la_cierra_con_motivo_y_confirmar(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $meson = Staff::factory()->asistente()->create();
        Sanctum::actingAs($meson);
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');
        $ejemplar->update(['disponible' => false]);
        $usuario = Usuario::factory()->create(['nombre' => 'Camila', 'apellido' => 'Rojas']);
        // Vencía el 13: con «Devolver» saldría una multa por atraso; acá no.
        $prestamo = Prestamo::factory()->create(['usuario_id' => $usuario->id, 'ejemplar_id' => $ejemplar->id, 'fecha_devolucion' => '2026-11-13']);
        $pib = PrestamoInterbibliotecario::where('ejemplar_id', $ejemplar->id)->first();

        // En Préstamos, el mesón ve de qué biblioteca es; la pestaña de Catalogación no es suya.
        $this->getJson("/api/prestamos?usuario_id={$usuario->id}")->assertOk()
            ->assertJsonPath('0.ejemplar.prestamo_interbibliotecario.id', $pib->id)
            ->assertJsonPath('0.ejemplar.prestamo_interbibliotecario.biblioteca', 'Biblioteca Central U. de Chile')
            ->assertJsonPath('0.ejemplar.prestamo_interbibliotecario.fecha_limite', '2026-11-16');
        $this->getJson('/api/prestamos-interbibliotecarios')->assertForbidden();

        $this->postJson("/api/prestamos-interbibliotecarios/{$pib->id}/devolver", [
            'motivo' => 'Lo devolvió directo a su biblioteca el 12/11',
            'confirmacion' => 'CONFIRMAR',
        ])->assertOk()
            ->assertJsonPath('devuelto_el', '2026-11-17')
            ->assertJsonPath('prestamo_cerrado', 'Camila Rojas');

        $prestamo->refresh();
        $this->assertSame('devuelto', $prestamo->estado);
        $this->assertSame($meson->id, $prestamo->devuelto_por_staff_id);
        $this->assertDatabaseCount('multas', 0);
        $this->assertSame('devuelto_propietaria', $ejemplar->fresh()->estado_proceso);
        $this->assertStringContainsString('Lo devolvió directo a su biblioteca el 12/11', $pib->fresh()->nota);
        $this->assertDatabaseHas('bitacora', ['accion' => 'prestamos.cerrar_por_devolucion_interbibliotecaria', 'entidad_id' => $prestamo->id]);
        $this->assertDatabaseHas('ejemplar_estado_historial', ['ejemplar_id' => $ejemplar->id, 'estado_nuevo' => 'devuelto_propietaria']);
    }

    public function test_lo_de_otra_biblioteca_no_se_da_de_baja(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'de_baja', 'motivo' => 'Extraviado', 'confirmacion' => 'CONFIRMAR'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Es de Biblioteca Central U. de Chile (préstamo interbibliotecario): no se da de baja, se le devuelve desde Catalogación › Préstamo interbibliotecario.');
        $this->postJson("/api/libros/{$ejemplar->libro_id}/baja", ['motivo' => 'Edición obsoleta', 'confirmacion' => 'CONFIRMAR'])->assertStatus(409);
        $this->assertSame('en_estante', $ejemplar->fresh()->estado_proceso);
    }

    public function test_se_corrige_la_fecha_limite_si_la_biblioteca_da_mas_plazo(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $ejemplar = $this->deOtraBiblioteca('2026-11-16');
        $pib = PrestamoInterbibliotecario::where('ejemplar_id', $ejemplar->id)->first();

        $this->patchJson("/api/prestamos-interbibliotecarios/{$pib->id}", ['fecha_limite' => '2026-11-30'])
            ->assertOk()
            ->assertJsonPath('fecha_limite', '2026-11-30')
            ->assertJsonPath('fecha_tope', '2026-11-27');
        $this->assertDatabaseHas('bitacora', ['accion' => 'libros.editar_interbibliotecario', 'entidad_id' => $pib->id]);

        $this->patchJson("/api/prestamos-interbibliotecarios/{$pib->id}", ['fecha_limite' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_limite');
    }

    public function test_el_reporte_de_prestamos_cuenta_lo_recibido_lo_devuelto_y_lo_prestado(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        Sanctum::actingAs(Staff::factory()->create());
        $devuelta = $this->deOtraBiblioteca('2026-11-16', '2026-11-02');
        PrestamoInterbibliotecario::where('ejemplar_id', $devuelta->id)->update(['devuelto_el' => '2026-11-16']);
        $devuelta->update(['estado_proceso' => 'devuelto_propietaria', 'disponible' => false]);
        $sigue = $this->deOtraBiblioteca('2026-12-15', '2026-11-18');
        PrestamoInterbibliotecario::where('ejemplar_id', $sigue->id)->update(['biblioteca' => 'Biblioteca UACh']);
        $propia = Ejemplar::factory()->create();

        Prestamo::factory()->count(2)->create(['ejemplar_id' => $devuelta->id, 'estado' => 'devuelto', 'fecha_prestamo' => '2026-11-05 10:00:00']);
        Prestamo::factory()->create(['ejemplar_id' => $sigue->id, 'fecha_prestamo' => '2026-11-19 10:00:00']);
        Prestamo::factory()->create(['ejemplar_id' => $propia->id, 'fecha_prestamo' => '2026-11-19 10:00:00']);

        $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=mes&fecha=2026-11-20')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('interbibliotecario.recibidos', 2)
            ->assertJsonPath('interbibliotecario.devueltos', 1)
            ->assertJsonPath('interbibliotecario.prestamos', 3)
            ->assertJsonPath('interbibliotecario.porBiblioteca', [
                ['label' => 'Biblioteca Central U. de Chile', 'value' => 1],
                ['label' => 'Biblioteca UACh', 'value' => 1],
            ]);

        // Con el filtro, solo los préstamos de ese material: también los de la copia ya devuelta.
        $this->getJson('/api/reportes/resumen?tab=prestamos&periodo=mes&fecha=2026-11-20&tipo_prestamo=interbibliotecario')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }
}
