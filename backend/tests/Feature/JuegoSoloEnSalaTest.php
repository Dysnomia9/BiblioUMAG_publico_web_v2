<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Los juegos no salen de la biblioteca: solo uso en sala. */
class JuegoSoloEnSalaTest extends TestCase
{
    use RefreshDatabase;

    private function copiaDeJuego(): Ejemplar
    {
        $juego = TipoMaterial::where('plantilla', 'juego_educativo')->firstOrFail();

        return Ejemplar::factory()->for(Libro::factory()->state(['tipo_material_id' => $juego->id]))->create(['codigo_barras' => '30000000000777']);
    }

    public function test_no_se_presta_para_llevar_pero_si_en_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $this->copiaDeJuego();
        $usuario = Usuario::factory()->create();

        $this->postJson('/api/prestamos', [
            'usuario_id' => $usuario->id,
            'codigo_barras' => '30000000000777',
            'fecha_prestamo' => now()->toDateString(),
            'fecha_devolucion' => $this->fechaConAtencion(7),
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Solo uso en sala: este material no se presta a domicilio (Todos los usuarios · Juego educativo · toda ubicación).');

        $this->postJson('/api/prestamos', ['usuario_id' => $usuario->id, 'codigo_barras' => '30000000000777', 'en_sala' => true])
            ->assertCreated()
            ->assertJsonPath('en_sala', true);
    }

    public function test_no_se_reserva_desde_el_portal(): void
    {
        $copia = $this->copiaDeJuego();
        Sanctum::actingAs(Usuario::factory()->create());

        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $copia->libro_id])->assertStatus(409);

        $this->assertTrue($copia->refresh()->disponible);
    }
}
