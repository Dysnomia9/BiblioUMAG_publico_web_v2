<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Ejemplar;
use App\Models\Multa;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Registro absoluto → Acciones del personal: quién hizo qué. */
class BitacoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestar_y_devolver_quedan_registrados_con_quien_lo_hizo(): void
    {
        $staff = Staff::factory()->create(['nombre' => 'Rosa Mesón']);
        Sanctum::actingAs($staff);
        $usuario = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez']);
        $ejemplar = Ejemplar::factory()->create(['estado_proceso' => 'en_estante', 'disponible' => true]);

        $prestamo = $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $this->fechaConAtencion(7),
        ])->assertCreated()->json();

        $this->patchJson("/api/prestamos/{$prestamo['id']}/devolver")->assertOk();

        $registros = Bitacora::orderBy('id')->get();
        $this->assertSame(['prestamos.prestar', 'prestamos.devolver'], $registros->pluck('accion')->all());
        $this->assertSame(['Rosa Mesón', 'Rosa Mesón'], $registros->pluck('actor_nombre')->all());
        $this->assertSame($staff->id, $registros[0]->staff_id);
        $this->assertStringStartsWith('Ana Pérez (RUT ', $registros[0]->afectado);
    }

    public function test_cursar_pagar_y_anular_multas_quedan_registrados(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $usuario = Usuario::factory()->create();

        $multa = $this->postJson('/api/multas', ['usuario_id' => $usuario->id, 'tipo' => 'deterioro', 'monto' => 5000, 'detalle' => 'Tapa rota'])
            ->assertCreated()->json();
        $this->patchJson("/api/multas/{$multa['id']}/pagar")->assertOk();
        $otra = Multa::factory()->create(['usuario_id' => $usuario->id, 'estado' => 'pendiente']);
        $this->patchJson("/api/multas/{$otra->id}/anular", ['motivo' => 'Cobrada por error'])->assertOk();

        $this->assertSame(['multas.cursar', 'multas.pagar', 'multas.anular'], Bitacora::orderBy('id')->pluck('accion')->all());
        $this->assertStringContainsString('$5.000', Bitacora::where('accion', 'multas.cursar')->value('descripcion'));
    }

    public function test_cambiar_el_estado_de_un_ejemplar_queda_registrado_con_antes_y_despues(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ejemplar = Ejemplar::factory()->create(['estado_proceso' => 'inventario']);

        $this->patchJson("/api/ejemplares/{$ejemplar->id}/estado", ['estado_proceso' => 'en_estante'])->assertOk();

        $registro = Bitacora::sole();
        $this->assertSame('libros.cambiar_estado', $registro->accion);
        $this->assertSame(['inventario', 'en_estante'], $registro->datos['estado']);
        $this->assertStringContainsString('Inventario → En estante', $registro->descripcion);
    }

    public function test_la_pantalla_filtra_por_modulo_persona_y_texto(): void
    {
        $rosa = Staff::factory()->admin()->create(['nombre' => 'Rosa']);
        $pedro = Staff::factory()->admin()->create(['nombre' => 'Pedro']);
        Sanctum::actingAs($rosa);
        \App\Support\Bitacora::registrar('prestamos.prestar', 'Prestó «Cálculo I»', actor: $rosa);
        \App\Support\Bitacora::registrar('multas.pagar', 'Registró el pago de una multa', actor: $pedro);
        \App\Support\Bitacora::registrar('multas.anular', 'Anuló una multa', actor: $rosa);

        $this->getJson('/api/bitacora')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/bitacora?modulo[]=multas')->assertOk()->assertJsonPath('total', 2);
        $this->getJson("/api/bitacora?staff_id={$rosa->id}")->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/bitacora?q=calculo')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('acciones.0.actor', 'Rosa')
            ->assertJsonPath('acciones.0.modulo', 'prestamos');
    }

    public function test_sin_permiso_de_registro_absoluto_no_se_ve_la_bitacora(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->getJson('/api/bitacora')->assertForbidden();
    }
}
