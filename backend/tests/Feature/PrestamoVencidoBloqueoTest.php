<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Quien tiene un libro vencido sin devolver no se lleva ni reserva más material hasta devolverlo. */
class PrestamoVencidoBloqueoTest extends TestCase
{
    use RefreshDatabase;

    private function conLibroVencido(Usuario $usuario, int $diasDeAtraso = 1): Prestamo
    {
        return Prestamo::factory()->create([
            'usuario_id' => $usuario->id,
            'ejemplar_id' => Ejemplar::factory()->for(Libro::factory())->create(['disponible' => false])->id,
            'tipo_item' => 'libro',
            'estado' => 'activo',
            'fecha_prestamo' => now()->subDays($diasDeAtraso + 7),
            'fecha_devolucion' => now()->subDays($diasDeAtraso),
        ]);
    }

    private function prestarLibro(Usuario $usuario, Ejemplar $ejemplar)
    {
        return $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => $ejemplar->codigo_barras,
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $this->fechaConAtencion(7),
        ]);
    }

    public function test_con_un_libro_vencido_no_se_presta_otro_desde_el_primer_dia_de_atraso(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $this->conLibroVencido($usuario, 1);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestarLibro($usuario, $ejemplar)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este usuario tiene 1 libro vencido sin devolver: debe devolverlo antes de llevarse o reservar material.');

        $this->assertTrue($ejemplar->fresh()->disponible);
    }

    public function test_el_libro_que_vence_hoy_todavia_no_bloquea(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $this->conLibroVencido($usuario, 0);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        // Sigue limitado por el máximo de libros a la vez, pero no por atraso.
        $this->prestarLibro($usuario, $ejemplar)
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => ! str_contains($m, 'vencido'));
    }

    public function test_con_un_libro_vencido_tampoco_se_presta_un_recurso_de_apoyo_ni_se_reserva(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $this->conLibroVencido($usuario, 3);
        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'tipo_item' => 'notebook', 'codigo_barras' => $equipo->codigo_barras])
            ->assertStatus(409);

        $this->postJson('/api/reservas-libro', ['usuario_id' => $usuario->id, 'codigo_barras' => $ejemplar->codigo_barras])
            ->assertStatus(409);

        $this->assertDatabaseCount('reservas_libro', 0);
    }

    public function test_el_portal_no_deja_reservar_y_avisa_el_motivo(): void
    {
        $usuario = Usuario::factory()->create();
        $this->conLibroVencido($usuario, 2);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        Sanctum::actingAs($usuario);

        $this->getJson('/api/mi/reservas-libro/condiciones')
            ->assertOk()
            ->assertJsonPath('bloqueo', 'Tienes un libro vencido: devuélvelo en la biblioteca antes de reservar libros.');

        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $ejemplar->libro_id])->assertStatus(409);
    }

    public function test_las_condiciones_de_prestamo_del_meson_muestran_el_atraso(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $this->conLibroVencido($usuario, 5);
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->getJson('/api/prestamos/condiciones?usuario_id='.$usuario->id.'&codigo_barras='.$ejemplar->codigo_barras)
            ->assertOk()
            ->assertJsonPath('motivo_rechazo', fn ($m) => str_contains((string) $m, 'vencido'));
    }

    public function test_al_devolver_el_libro_vencido_se_desbloquea_si_no_queda_multa(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        $prestamo = $this->conLibroVencido($usuario, 1);
        // Sin tarifa, la devolución tardía no genera multa: solo se levanta el bloqueo por atraso.
        config(['multas.monto_dia' => 0]);

        $this->patchJson("/api/prestamos/{$prestamo->id}/devolver")->assertOk();

        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();
        $this->prestarLibro($usuario, $ejemplar)->assertCreated();
    }

    public function test_otro_usuario_sin_atrasos_no_se_ve_afectado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $this->conLibroVencido(Usuario::factory()->create(), 10);
        $otro = Usuario::factory()->create();
        $ejemplar = Ejemplar::factory()->for(Libro::factory())->create();

        $this->prestarLibro($otro, $ejemplar)->assertCreated();
    }
}
