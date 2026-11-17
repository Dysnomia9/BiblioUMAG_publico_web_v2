<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestamoDevolucionRapidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create());
        config(['biblioteca.horario' => array_fill(1, 7, ['08:45', '20:30'])]);
    }

    public function test_escanear_y_devolver_sin_multa_libera_la_copia_y_registra_la_devolucion(): void
    {
        $copia = Ejemplar::factory()->create(['disponible' => false, 'volumen' => 't.2 c.3', 'tomo' => 't.2', 'copia_tomo' => 3]);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $copia->id, 'codigo_barras' => $copia->codigo_barras]);

        $this->getJson('/api/prestamos/por-codigo?codigo='.$copia->codigo_barras)
            ->assertOk()->assertJsonPath('id', $prestamo->id)
            ->assertJsonPath('usuario.id', $prestamo->usuario_id)
            ->assertJsonPath('usuario.nombre', $prestamo->usuario->nombre)
            ->assertJsonPath('ejemplar.volumen', 't.2 c.3')
            ->assertJsonPath('ejemplar.etiqueta_copia', 'c.3')
            ->assertJsonPath('ejemplar.libro.titulo', $copia->libro->titulo);
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['solo_sin_multa' => true])
            ->assertOk()->assertJsonPath('estado', 'devuelto')->assertJsonCount(0, 'multas')
            ->assertJsonPath('usuario.id', $prestamo->usuario_id)
            ->assertJsonPath('usuario.apellido', $prestamo->usuario->apellido)
            ->assertJsonPath('ejemplar.codigo_barras', $copia->codigo_barras)
            ->assertJsonPath('ejemplar.etiqueta_copia', 'c.3')
            ->assertJsonPath('ejemplar.libro.titulo', $copia->libro->titulo);

        $this->assertTrue($copia->fresh()->disponible);
        $this->assertDatabaseHas('bitacora', ['accion' => 'prestamos.devolver', 'entidad_id' => $prestamo->id]);
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['solo_sin_multa' => true])->assertConflict();
        $this->assertDatabaseCount('multas', 0);
    }

    public function test_un_atraso_exige_confirmacion_sin_modificar_prestamo_ni_copia(): void
    {
        $copia = Ejemplar::factory()->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create(['ejemplar_id' => $copia->id, 'fecha_devolucion' => now()->subDays(3)]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['solo_sin_multa' => true, 'multar' => false])
            ->assertConflict()->assertJsonPath('requiere_confirmacion', true);
        $this->assertSame('activo', $prestamo->fresh()->estado);
        $this->assertNull($prestamo->fresh()->fecha_devolucion_real);
        $this->assertFalse($copia->fresh()->disponible);
        $this->assertDatabaseCount('multas', 0);
        $this->assertDatabaseMissing('bitacora', ['accion' => 'prestamos.devolver', 'entidad_id' => $prestamo->id]);

        // La decisión expresa del mesón sigue permitiendo devolver sin cursar la multa.
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['multar' => false])
            ->assertOk()->assertJsonPath('estado', 'devuelto')
            ->assertJsonPath('usuario.id', $prestamo->usuario_id)
            ->assertJsonPath('ejemplar.id', $copia->id);
    }

    public function test_una_multa_pendiente_del_usuario_tambien_exige_confirmacion(): void
    {
        $prestamo = Prestamo::factory()->create();
        $multa = Multa::factory()->create(['usuario_id' => $prestamo->usuario_id]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['solo_sin_multa' => true])
            ->assertConflict()->assertJsonPath('requiere_confirmacion', true);
        $this->assertSame('activo', $prestamo->fresh()->estado);
        $this->assertSame('pendiente', $multa->fresh()->estado);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk();
    }

    public function test_una_multa_pagada_no_impide_la_devolucion_automatica_de_un_equipo(): void
    {
        $equipo = Equipo::factory()->create(['disponible' => false]);
        $prestamo = Prestamo::factory()->create(['tipo_item' => $equipo->tipo, 'equipo_id' => $equipo->id, 'codigo_barras' => $equipo->codigo_barras]);
        Multa::factory()->pagada()->create(['usuario_id' => $prestamo->usuario_id]);

        $this->getJson('/api/prestamos/por-codigo?codigo='.$equipo->codigo_barras)->assertOk()
            ->assertJsonPath('usuario.id', $prestamo->usuario_id)
            ->assertJsonPath('equipo.codigo_inventario', $equipo->codigo_inventario);
        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver", ['solo_sin_multa' => true])
            ->assertOk()->assertJsonPath('estado', 'devuelto')
            ->assertJsonPath('usuario.id', $prestamo->usuario_id)
            ->assertJsonPath('equipo.tipo', $equipo->tipo)
            ->assertJsonPath('equipo.codigo_inventario', $equipo->codigo_inventario)
            ->assertJsonPath('equipo.codigo_barras', $equipo->codigo_barras);
        $this->assertTrue($equipo->fresh()->disponible);
    }
}
