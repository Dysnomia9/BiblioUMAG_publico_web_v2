<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Bitacora;
use App\Models\Ejemplar;
use App\Models\Entrada;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\ExportarSistemaService;
use App\Services\ImportarSistemaService;
use App\Support\TraspasoEsquema;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TraspasoSistemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Transacciones reales: el exportador requiere REPEATABLE READ y se
        // verifican secuencias. migrate:fresh evita los down() irreversibles del catálogo.
        $this->artisan('migrate:fresh')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS horizon_etl CASCADE');
        RefreshDatabaseState::$migrated = false;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exporta_el_mes_y_las_dependencias_sin_arrastrar_el_historial_importado(): void
    {
        $usuario = Usuario::factory()->create(['activo' => false, 'vigente_hasta' => '2025-03-31']);
        $nuevo = Libro::factory()->create();
        $antiguo = Libro::factory()->create(['created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $sinUso = Libro::factory()->create(['created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $importado = Libro::factory()->create();
        $copia = Ejemplar::factory()->create(['libro_id' => $antiguo->id, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $prestamo = Prestamo::factory()->create(['usuario_id' => $usuario->id, 'ejemplar_id' => $copia->id]);
        DB::table('ejemplar_estado_historial')->insert([
            'ejemplar_id' => $copia->id, 'estado_anterior' => 'inventario', 'estado_nuevo' => 'en_estante',
            'created_at' => '2026-10-01', 'updated_at' => now(),
        ]);
        $historico = Prestamo::factory()->create(['usuario_id' => $usuario->id, 'estado' => 'devuelto', 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $horizon = Prestamo::factory()->create(['usuario_id' => $usuario->id, 'estado' => 'devuelto']);
        DB::statement('CREATE SCHEMA horizon_etl');
        DB::statement('CREATE TABLE horizon_etl.libro (bib_num integer primary key, libro_id bigint)');
        DB::statement('CREATE TABLE horizon_etl.prestamo (clave text primary key, prestamo_id bigint)');
        DB::table('horizon_etl.libro')->insert(['bib_num' => 100, 'libro_id' => $importado->id]);
        DB::table('horizon_etl.prestamo')->insert(['clave' => 'anterior-1', 'prestamo_id' => $horizon->id]);
        Multa::factory()->create(['usuario_id' => $usuario->id, 'origen' => 'importada', 'referencia_externa' => 'horizon:1']);
        $futura = Reserva::factory()->create(['usuario_id' => $usuario->id, 'fecha' => '2026-12-02']);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'created_at' => '2026-12-01', 'updated_at' => '2026-12-01']);

        $archivo = $this->exportar();
        $libros = collect($archivo['tablas']['libros'])->keyBy('datos.id');
        $this->assertSame('reemplazar', $libros[$nuevo->id]['modo']);
        $this->assertSame('referencia', $libros[$antiguo->id]['modo']);
        $this->assertArrayNotHasKey($sinUso->id, $libros->all());
        $this->assertArrayNotHasKey($importado->id, $libros->all());
        $this->assertSame([$prestamo->id], array_column(array_column($archivo['tablas']['prestamos'], 'datos'), 'id'));
        $this->assertNotContains($historico->id, array_column(array_column($archivo['tablas']['prestamos'], 'datos'), 'id'));
        $this->assertSame($futura->id, $archivo['tablas']['reservas'][0]['datos']['id']);
        $this->assertEmpty($archivo['tablas']['multas']);
        $this->assertEmpty($archivo['tablas']['entradas']);
        $this->assertEmpty($archivo['tablas']['ejemplar_estado_historial']);
        $this->assertFalse($archivo['tablas']['usuarios'][0]['datos']['activo']);
        $this->assertSame('2025-03-31', $archivo['tablas']['usuarios'][0]['datos']['vigente_hasta']);
        $this->assertSame($archivo['origen'], $this->exportar()['origen']);
    }

    public function test_traspasa_con_ids_distintos_conserva_relaciones_reportes_y_es_repetible(): void
    {
        $datos = $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        // Ocupa un ID del origen con una persona diferente: nunca debe usarlo como identidad.
        $ajeno = Usuario::factory()->create(['id' => $datos['usuario']->id, 'rut' => '99.999.999-9']);
        $resumen = $this->importar($archivo);
        $this->assertGreaterThan(0, array_sum(array_column($resumen, 'creados')));
        $usuario = Usuario::where('rut', $datos['usuario']->rut)->firstOrFail();
        $staff = Staff::where('email', $datos['staff']->email)->firstOrFail();
        $libro = Libro::where('numero_ficha', $datos['libro']->numero_ficha)->firstOrFail();
        $copia = Ejemplar::where('codigo_barras', $datos['copia']->codigo_barras)->firstOrFail();
        $this->assertNotSame($datos['usuario']->id, $usuario->id);
        $this->assertSame($datos['usuario']->getRawOriginal('password'), $usuario->getRawOriginal('password'));
        $this->assertSame($datos['staff']->getRawOriginal('password'), $staff->getRawOriginal('password'));
        $this->assertFalse($usuario->getRawOriginal('activo'));
        $this->assertSame('2025-03-31', $usuario->getRawOriginal('vigente_hasta'));
        $this->assertDatabaseHas('usuarios', ['id' => $ajeno->id, 'rut' => '99.999.999-9']);
        $this->assertDatabaseHas('usuario_correos', ['usuario_id' => $usuario->id, 'email' => 'alias@umag.cl']);
        $this->assertDatabaseHas('ejemplares', ['id' => $copia->id, 'libro_id' => $libro->id, 'disponible' => false]);
        $prestamo = Prestamo::firstOrFail();
        $reserva = Reserva::firstOrFail();
        $this->assertDatabaseHas('prestamos', ['id' => $prestamo->id, 'usuario_id' => $usuario->id, 'ejemplar_id' => $copia->id, 'prestado_por_staff_id' => $staff->id]);
        $this->assertDatabaseHas('multas', ['prestamo_id' => $prestamo->id, 'usuario_id' => $usuario->id, 'estado' => 'pagada']);
        $this->assertDatabaseHas('reserva_participantes', ['reserva_id' => $reserva->id, 'usuario_id' => $usuario->id]);
        $this->assertDatabaseHas('bitacora', ['entidad_tipo' => 'Prestamo', 'entidad_id' => $prestamo->id, 'staff_id' => $staff->id]);
        $this->assertSame(['Autora de prueba'], $libro->autores()->pluck('nombre')->all());
        Sanctum::actingAs($staff);
        foreach (['prestamos', 'ingresos', 'logias'] as $tab) {
            $this->getJson('/api/reportes/resumen?periodo=mes&fecha=2026-11-18&tab='.$tab)->assertOk()->assertJsonPath('total', 1);
        }
        $otra = $this->importar($archivo);
        $this->assertSame(0, array_sum(array_column($otra, 'creados')));
        $this->assertSame(0, array_sum(array_column($otra, 'actualizados')));
        $this->assertSame(0, array_sum(array_column($otra, 'eliminados')));
        $this->assertDatabaseCount('prestamos', 1);
        $this->assertDatabaseCount('entradas', 1);
        $this->assertDatabaseCount('reservas', 1);
        $this->assertDatabaseCount('reservas_libro', 1);
        $this->assertDatabaseCount('multas', 1);
        $this->assertDatabaseCount('bitacora', 1);
    }

    public function test_la_simulacion_no_cambia_filas_mapeos_tokens_ni_secuencias(): void
    {
        $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        $antes = $this->fotografia();
        $secuencias = DB::select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename");
        $resumen = $this->importar($archivo, true);
        $this->assertGreaterThan(0, array_sum(array_column($resumen, 'creados')));
        $this->assertSame($antes, $this->fotografia());
        $this->assertEquals($secuencias, DB::select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename"));
    }

    public function test_un_nuevo_respaldo_actualiza_el_mismo_movimiento_y_sincroniza_las_relaciones(): void
    {
        $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        $this->importar($archivo);
        $prestamoId = Prestamo::value('id');
        $usuario = Usuario::firstOrFail();
        $usuario->correos()->create(['email' => 'sobrante@umag.cl']);
        $archivo['tablas']['prestamos'][0]['datos']['estado'] = 'devuelto';
        $archivo['tablas']['prestamos'][0]['datos']['fecha_devolucion_real'] = '2026-11-18 12:00:00';
        $archivo['tablas']['ejemplares'][0]['datos']['disponible'] = true;
        $archivo['tablas']['usuarios'][0]['datos']['nombre'] = 'Nombre corregido';
        $archivo['tablas']['usuarios'][0]['datos']['activo'] = true;
        $archivo['tablas']['usuarios'][0]['datos']['vigente_hasta'] = '2027-03-31';
        $archivo['tablas']['libro_autor'] = [];
        $this->importar($archivo);
        $this->assertDatabaseCount('prestamos', 1);
        $this->assertDatabaseHas('prestamos', ['id' => $prestamoId, 'estado' => 'devuelto']);
        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id, 'nombre' => 'Nombre corregido', 'activo' => true, 'vigente_hasta' => '2027-03-31']);
        $this->assertDatabaseMissing('usuario_correos', ['email' => 'sobrante@umag.cl']);
        $this->assertDatabaseCount('libro_autor', 0);
    }

    public function test_actualiza_usuario_por_documento_y_revoca_su_sesion_si_cambia_el_acceso(): void
    {
        $origen = Usuario::factory()->create(['rut' => '11.111.111-1', 'activo' => false]);
        $archivo = $this->exportar();
        $this->vaciar();
        $destino = Usuario::factory()->create(['rut' => '111111111', 'activo' => true]);
        $destino->createToken('sesion');
        $this->importar($archivo, true);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->importar($archivo);
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('usuarios', ['id' => $destino->id, 'rut' => $origen->rut, 'activo' => false]);
    }

    public function test_reutiliza_el_catalogo_horizon_del_servidor_y_actualiza_su_disponibilidad(): void
    {
        $libro = Libro::factory()->create(['created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $copia = Ejemplar::factory()->create(['libro_id' => $libro->id, 'disponible' => false, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        Prestamo::factory()->create(['ejemplar_id' => $copia->id]);
        $archivo = $this->exportar();
        $archivo['tablas']['libros'][0]['horizon'] = 50;
        $archivo['tablas']['ejemplares'][0]['horizon'] = 60;
        $this->vaciar();
        $targetLibro = Libro::factory()->create(['titulo' => 'Catálogo del servidor']);
        $targetCopia = Ejemplar::factory()->create(['libro_id' => $targetLibro->id, 'disponible' => true]);
        DB::statement('CREATE SCHEMA horizon_etl');
        DB::statement('CREATE TABLE horizon_etl.libro (bib_num integer primary key, libro_id bigint)');
        DB::statement('CREATE TABLE horizon_etl.ejemplar (item_num integer primary key, ejemplar_id bigint)');
        DB::table('horizon_etl.libro')->insert(['bib_num' => 50, 'libro_id' => $targetLibro->id]);
        DB::table('horizon_etl.ejemplar')->insert(['item_num' => 60, 'ejemplar_id' => $targetCopia->id]);
        $this->importar($archivo);
        $this->assertDatabaseCount('libros', 1);
        $this->assertDatabaseCount('ejemplares', 1);
        $this->assertDatabaseHas('libros', ['id' => $targetLibro->id, 'titulo' => 'Catálogo del servidor']);
        $this->assertDatabaseHas('ejemplares', ['id' => $targetCopia->id, 'disponible' => false]);
        $this->assertDatabaseHas('prestamos', ['ejemplar_id' => $targetCopia->id]);
    }

    public function test_un_conflicto_revierte_toda_la_importacion(): void
    {
        $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        $ajeno = Usuario::factory()->create();
        $ajeno->correos()->create(['email' => $archivo['tablas']['usuarios'][0]['datos']['email']]);
        $antes = $this->fotografia();
        try {
            $this->importar($archivo);
            $this->fail('Debió rechazar el correo compartido.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('correo', $e->getMessage());
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_conserva_recursos_y_personal_dados_de_baja_y_omite_columnas_generadas(): void
    {
        $staff = Staff::factory()->create(['activo' => false]);
        $staff->delete();
        $equipo = Equipo::factory()->create(['tipo' => 'notebook', 'activo' => false, 'motivo_baja' => 'Reemplazado']);
        $equipo->delete();
        Prestamo::factory()->create(['equipo_id' => $equipo->id, 'tipo_item' => 'notebook', 'estado' => 'devuelto', 'prestado_por_staff_id' => $staff->id]);
        $archivo = $this->exportar();
        $this->assertArrayNotHasKey('categoria_recurso_clave', $archivo['tablas']['prestamos'][0]['datos']);
        $this->vaciar();
        $this->importar($archivo, true);
        $this->assertDatabaseCount('equipos', 0);
        $this->importar($archivo);
        $destino = Equipo::withTrashed()->firstOrFail();
        $this->assertTrue($destino->trashed());
        $this->assertTrue(Staff::withTrashed()->firstOrFail()->trashed());
        $this->assertDatabaseHas('prestamos', ['equipo_id' => $destino->id, 'categoria_recurso_clave' => 'notebook']);
        $this->assertSame(0, array_sum(array_column($this->importar($archivo), 'creados')));
    }

    public function test_conserva_correos_compartidos_heredados_pero_rechaza_un_dueno_adicional_del_servidor(): void
    {
        Usuario::factory()->count(2)->create(['email' => 'heredado@umag.cl']);
        $archivo = $this->exportar();
        $this->vaciar();
        $this->importar($archivo);
        $this->assertDatabaseCount('usuarios', 2);
        $this->assertSame(0, array_sum(array_column($this->importar($archivo), 'creados')));
        Usuario::factory()->create(['email' => 'heredado@umag.cl']);
        $antes = $this->fotografia();
        try {
            $this->importar($archivo);
            $this->fail('Debió rechazar una nueva colisión con la persona adicional del servidor.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('correo', $e->getMessage());
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_no_crea_un_segundo_prestamo_activo_para_un_recurso_ya_ocupado(): void
    {
        $datos = $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        // Primero llega una circulación distinta, fuera de este traspaso.
        $libro = Libro::factory()->create(['titulo' => 'Libro del servidor']);
        $libro->refresh();
        $copia = Ejemplar::factory()->create(['libro_id' => $libro->id, 'codigo_barras' => $datos['copia']->codigo_barras]);
        Prestamo::factory()->create(['ejemplar_id' => $copia->id]);
        // El catálogo referenciado se reutiliza sin intentar sobrescribir su numeración.
        $archivo['tablas']['libros'][0]['modo'] = 'referencia';
        $archivo['tablas']['libros'][0]['datos']['numero_ficha'] = $libro->numero_ficha;
        $archivo['tablas']['libros'][0]['datos']['created_at'] = $libro->getRawOriginal('created_at');
        $archivo['tablas']['ejemplares'][0]['modo'] = 'referencia';
        $antes = $this->fotografia();
        try {
            $this->importar($archivo);
            $this->fail('Debió rechazar los préstamos activos incompatibles.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('activos duplicados', $e->getMessage());
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_rechaza_dependencias_faltantes_y_tablas_desconocidas_sin_cambios(): void
    {
        $this->datos();
        $archivo = $this->exportar();
        $this->vaciar();
        $antes = $this->fotografia();
        $malo = $archivo;
        $malo['tablas']['ejemplares'] = [];
        try {
            $this->importar($malo);
            $this->fail('Debió rechazar una dependencia faltante.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dependencia', $e->getMessage());
        }
        $archivo['tablas']['personal_access_tokens'] = [];
        try {
            $this->importar($archivo);
            $this->fail('Debió rechazar la tabla desconocida.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tablas', $e->getMessage());
        }
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_los_comandos_crean_archivo_privado_simulan_y_rechazan_un_mes_invalido(): void
    {
        $this->datos();
        $ruta = storage_path('framework/testing/traspaso-'.Str::uuid().'.json');
        try {
            $this->artisan('sistema:exportar', ['--mes' => '2026-13', '--archivo' => $ruta])->assertFailed();
            $this->assertFileDoesNotExist($ruta);
            $this->artisan('sistema:exportar', ['--mes' => '2026-11', '--archivo' => $ruta])->assertSuccessful();
            $this->assertSame(0600, fileperms($ruta) & 0777);
            $this->artisan('sistema:exportar', ['--archivo' => $ruta])->assertFailed();
            $this->vaciar();
            $this->artisan('sistema:importar', ['archivo' => $ruta, '--simular' => true])->assertSuccessful();
            $this->assertDatabaseCount('prestamos', 0);
            $this->artisan('sistema:importar', ['archivo' => $ruta])->assertSuccessful();
            $this->assertDatabaseCount('prestamos', 1);
            $this->artisan('sistema:importar', ['archivo' => $ruta])->assertSuccessful();
            $this->assertDatabaseCount('prestamos', 1);
        } finally {
            if (file_exists($ruta)) {
                unlink($ruta);
            }
        }
    }

    private function datos(): array
    {
        $staff = Staff::factory()->admin()->create();
        $usuario = Usuario::factory()->create(['activo' => false, 'vigente_hasta' => '2025-03-31']);
        $usuario->correos()->create(['email' => 'alias@umag.cl']);
        $libro = Libro::factory()->create(['titulo' => 'Libro nuevo', 'catalogado_por_staff_id' => $staff->id]);
        $libro->refresh();
        $libro->autores()->attach(Autor::factory()->create(['nombre' => 'Autora de prueba']), ['orden' => 0, 'rol' => null]);
        $copia = Ejemplar::factory()->create(['libro_id' => $libro->id, 'disponible' => false]);
        $prestamo = Prestamo::factory()->create(['usuario_id' => $usuario->id, 'ejemplar_id' => $copia->id, 'prestado_por_staff_id' => $staff->id]);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'registrado_por_staff_id' => $staff->id]);
        Reserva::factory()->create(['usuario_id' => $usuario->id, 'hora_prestamo_real' => now()->subHour()])->participantes()->attach($usuario);
        ReservaLibro::factory()->create(['usuario_id' => $usuario->id, 'libro_id' => $libro->id, 'ejemplar_id' => $copia->id, 'estado' => 'retirado']);
        Multa::factory()->pagada()->create(['usuario_id' => $usuario->id, 'prestamo_id' => $prestamo->id, 'pagada_por_staff_id' => $staff->id]);
        Bitacora::create(['staff_id' => $staff->id, 'actor_nombre' => $staff->nombre, 'modulo' => 'prestamos', 'accion' => 'prestamos.prestar', 'descripcion' => 'Prestó el libro', 'entidad_tipo' => 'Prestamo', 'entidad_id' => $prestamo->id]);

        return compact('staff', 'usuario', 'libro', 'copia', 'prestamo');
    }

    private function exportar(): array
    {
        // El JSON real atraviesa esta misma conversión (incluidos tipos y hashes).
        return json_decode(json_encode(app(ExportarSistemaService::class)->exportar('2026-11'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importar(array $archivo, bool $simular = false): array
    {
        return app(ImportarSistemaService::class)->importar($archivo, $simular);
    }

    private function vaciar(): void
    {
        foreach (array_reverse(array_keys(TraspasoEsquema::CLAVES)) as $tabla) {
            DB::table($tabla)->delete();
        }
        DB::table('traspaso_registros')->delete();
        DB::table('traspaso_instancia')->delete();
        DB::table('traspaso_instancia')->insert(['id' => 1, 'uuid' => (string) Str::uuid()]);
    }

    private function fotografia(): array
    {
        $filas = [];
        foreach ([...array_keys(TraspasoEsquema::CLAVES), 'traspaso_registros', 'personal_access_tokens', 'traspaso_instancia'] as $tabla) {
            $filas[$tabla] = DB::table($tabla)->get()->map(fn ($fila) => (array) $fila)->all();
        }

        return $filas;
    }
}
