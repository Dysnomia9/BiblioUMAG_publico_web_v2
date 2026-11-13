<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Services\ReservaLibroService;
use App\Support\TomoEjemplar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TomoEjemplarTest extends TestCase
{
    use RefreshDatabase;

    public function test_lee_el_tomo_y_la_copia_como_los_escribe_horizon(): void
    {
        $this->assertSame(['v.1', 2], TomoEjemplar::desdeEtiqueta('v.1 c.2'));
        $this->assertSame(['v.1', 3], TomoEjemplar::desdeEtiqueta('v.1-c.3'));
        $this->assertSame(['t.4', null], TomoEjemplar::desdeEtiqueta('t.4'));
        $this->assertSame(['t.2', null], TomoEjemplar::desdeEtiqueta('Tomo 2'));
        $this->assertSame([null, null], TomoEjemplar::desdeEtiqueta('No. 367 (Ene., 2014)'));
    }

    public function test_al_agregar_una_copia_de_un_tomo_se_calcula_la_siguiente_de_ese_tomo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $libro = Libro::factory()->create();
        // Como vino de Horizon: «t.1» sola (la primera, sin «c.») y «t.2 c.1»
        Ejemplar::factory()->for($libro)->create(['volumen' => 't.1', 'tomo' => 't.1', 'copia_tomo' => null]);
        Ejemplar::factory()->for($libro)->create(['volumen' => 't.2 c.1', 'tomo' => 't.2', 'copia_tomo' => 1]);

        // Después de la «t.1» sola viene la c.1, no la c.2
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000201', 'tomo' => 'Tomo 1'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.1 c.1')->assertJsonPath('copia_tomo', 1);
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000202', 'tomo' => 't.1'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.1 c.2');
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000203', 'tomo' => 't.2'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.2 c.2');
        // Un tomo nuevo: su primera copia va sin «c.»
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000204', 'tomo' => 't.3'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.3')->assertJsonPath('copia_tomo', null)
            ->assertJsonPath('etiqueta_copia', null);
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000000205', 'tomo' => 't.3'])
            ->assertStatus(201)->assertJsonPath('volumen', 't.3 c.1')->assertJsonPath('etiqueta_copia', 'c.1');
    }

    public function test_sin_tomo_la_primera_copia_no_lleva_c_y_las_demas_siguen_la_de_horizon(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        // Título nuevo: la primera sin «c.», luego c.1 y c.2
        $nuevo = Libro::factory()->create();
        $this->postJson('/api/ejemplares', ['libro_id' => $nuevo->id, 'codigo_barras' => '30000000000301'])
            ->assertStatus(201)->assertJsonPath('etiqueta_copia', null);
        $this->postJson('/api/ejemplares', ['libro_id' => $nuevo->id, 'codigo_barras' => '30000000000302'])
            ->assertStatus(201)->assertJsonPath('etiqueta_copia', 'c.1');
        $this->postJson('/api/ejemplares', ['libro_id' => $nuevo->id, 'codigo_barras' => '30000000000303'])
            ->assertStatus(201)->assertJsonPath('etiqueta_copia', 'c.2');

        // Migrado de Horizon (sin «c.», c.1 y c.2, con otros números internos): la que sigue es la c.3
        $migrado = Libro::factory()->create();
        foreach ([[1, null], [2, 1], [3, 2]] as [$numero, $copia]) {
            $e = Ejemplar::factory()->for($migrado)->create(['numero_copia' => $numero, 'copia_tomo' => $copia]);
            Ejemplar::whereKey($e->id)->update(['fecha_ingreso_horizon' => '2011-04-18']);
        }
        $this->postJson('/api/ejemplares', ['libro_id' => $migrado->id, 'codigo_barras' => '30000000000304'])
            ->assertStatus(201)->assertJsonPath('etiqueta_copia', 'c.3')->assertJsonPath('numero_copia', 4);
    }

    public function test_corregir_el_volumen_a_mano_actualiza_el_tomo(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $copia = Ejemplar::factory()->create(['volumen' => 't.1', 'tomo' => 't.1']);

        $this->patchJson("/api/ejemplares/{$copia->id}", ['volumen' => 't.3 c.2'])->assertOk();
        $this->assertSame(['t.3', 2], [$copia->fresh()->tomo, $copia->fresh()->copia_tomo]);
    }

    public function test_la_copia_se_rotula_con_el_c_de_horizon_y_no_con_el_numero_interno(): void
    {
        $libro = Libro::factory()->create();
        // De Horizon: «c.2» -> copia_tomo 2 (su número interno es otro); sin «c.» -> sin etiqueta
        $conC = Ejemplar::factory()->for($libro)->create(['numero_copia' => 5, 'copia_tomo' => 2, 'fecha_ingreso_horizon' => '2001-01-01']);
        $sinC = Ejemplar::factory()->for($libro)->create(['numero_copia' => 6, 'copia_tomo' => null, 'fecha_ingreso_horizon' => '2001-01-01']);
        // Creada en BiblioUMAG: la «c.N» que se le puso; sin ella, sin número (nunca el interno)
        $nueva = Ejemplar::factory()->for($libro)->create(['numero_copia' => 7, 'copia_tomo' => 3]);
        $sinNumero = Ejemplar::factory()->for($libro)->create(['numero_copia' => 8, 'copia_tomo' => null]);

        $this->assertSame('c.2', $conC->fresh()->etiqueta_copia);
        $this->assertNull($sinC->fresh()->etiqueta_copia);
        $this->assertSame('c.3', $nueva->fresh()->etiqueta_copia);
        $this->assertNull($sinNumero->fresh()->etiqueta_copia);
    }

    public function test_en_el_portal_se_reserva_el_tomo_elegido_y_la_fila_espera_ese_tomo(): void
    {
        $libro = Libro::factory()->create();
        $t1 = Ejemplar::factory()->for($libro)->create(['tomo' => 't.1', 'volumen' => 't.1', 'disponible' => false]);
        Ejemplar::factory()->for($libro)->create(['tomo' => 't.2', 'volumen' => 't.2', 'disponible' => true]);

        $usuario = Usuario::factory()->create();
        Sanctum::actingAs($usuario);
        $fila = collect($this->getJson('/api/mi/catalogo')->assertOk()->json('data'))->firstWhere('id', $libro->id);
        $this->assertSame([['tomo' => 't.1', 'copias' => 1, 'disponibles' => 0], ['tomo' => 't.2', 'copias' => 1, 'disponibles' => 1]], $fila['tomos']);

        // Pide el t.1 (prestado): queda en la fila de ese tomo, aunque el t.2 esté libre.
        $this->postJson('/api/mi/reservas-libro', ['libro_id' => $libro->id, 'tomo' => 't.1'])
            ->assertStatus(201)->assertJsonPath('estado', 'en_cola')->assertJsonPath('tomo', 't.1');

        // Cuando vuelve una copia del t.2 no se le aparta; cuando vuelve la del t.1, sí.
        $servicio = app(ReservaLibroService::class);
        $otraDelT2 = Ejemplar::factory()->for($libro)->create(['tomo' => 't.2', 'disponible' => false]);
        $this->assertNull($servicio->liberarLibro($otraDelT2));
        $apartada = $servicio->liberarLibro($t1);
        $this->assertSame($t1->id, $apartada?->ejemplar_id);
        $this->assertSame('pendiente', ReservaLibro::first()->estado);
    }

    public function test_tomo_y_volumen_juntos_como_en_horizon(): void
    {
        $this->assertSame(['t.1-v.II', null], TomoEjemplar::desdeEtiqueta('t.1-v.II'));
        $this->assertSame(['t.2-v.I', 1], TomoEjemplar::desdeEtiqueta('t.2-v.I-c.1'));
        $this->assertSame(['t.3-v.1', 1], TomoEjemplar::desdeEtiqueta('t.3- v.1-c.1'));
        $this->assertSame(['t.4-v.II', 1], TomoEjemplar::desdeEtiqueta('t.4-vol.II-c.1'));
        $this->assertSame(['v.1-t.2', 3], TomoEjemplar::desdeEtiqueta('v.1-t.2-c.3'));
        $this->assertSame(['t.1-v.2', null], TomoEjemplar::desdeEtiqueta('t.1 v.2'));
        $this->assertSame([null, null], TomoEjemplar::desdeEtiqueta('t.1 t.2'));
        $this->assertSame([null, null], TomoEjemplar::desdeEtiqueta('Vol 1 No 1 (2009)'));
        $this->assertSame('t.1-v.II', TomoEjemplar::normalizar('Tomo 1 Volumen II'));
    }

    public function test_las_copias_se_numeran_dentro_del_tomo_y_volumen(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Staff::factory()->admin()->create());
        $libro = \App\Models\Libro::factory()->create();
        $agregar = fn (string $codigo, string $tomo) => $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => $codigo, 'tomo' => $tomo])->assertCreated();

        $agregar('30000000888001', 't.1-v.II')->assertJsonPath('tomo', 't.1-v.II')->assertJsonPath('volumen', 't.1-v.II');
        $agregar('30000000888002', 't.1 v.ii')->assertJsonPath('volumen', 't.1-v.II c.1');
        // Otro volumen del mismo tomo empieza su propia numeración
        $agregar('30000000888003', 't.1-v.I')->assertJsonPath('volumen', 't.1-v.I');
        $agregar('30000000888004', 't.1')->assertJsonPath('volumen', 't.1');
    }

    public function test_la_copia_lleva_el_numero_que_se_escribe_o_ninguno(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Staff::factory()->admin()->create());
        $libro = \App\Models\Libro::factory()->create();
        $agregar = fn (array $datos) => $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, ...$datos]);

        // Sin número: no se inventa uno
        $agregar(['codigo_barras' => '30000000999001', 'copia' => null])->assertCreated()->assertJsonPath('etiqueta_copia', null);
        // Con número, también en un tomo
        $agregar(['codigo_barras' => '30000000999002', 'copia' => 2])->assertCreated()->assertJsonPath('etiqueta_copia', 'c.2');
        $agregar(['codigo_barras' => '30000000999003', 'tomo' => 't.1-v.II', 'copia' => 3])->assertCreated()->assertJsonPath('volumen', 't.1-v.II c.3');
        // La misma «c.N» no se repite en el mismo tomo, pero sí en otro
        $agregar(['codigo_barras' => '30000000999004', 'tomo' => 't.1-v.II', 'copia' => 3])->assertStatus(422)->assertJsonValidationErrors('copia');
        $agregar(['codigo_barras' => '30000000999005', 'tomo' => 't.2', 'copia' => 3])->assertCreated();
        $agregar(['codigo_barras' => '30000000999006', 'copia' => 2])->assertStatus(422);
    }

    public function test_solo_volumen_y_designacion_con_copia_al_editar(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Staff::factory()->admin()->create());
        $libro = \App\Models\Libro::factory()->create();
        // Solo volumen, sin tomo
        $this->postJson('/api/ejemplares', ['libro_id' => $libro->id, 'codigo_barras' => '30000000777101', 'tomo' => 'v.2', 'copia' => null])
            ->assertCreated()->assertJsonPath('tomo', 'v.2')->assertJsonPath('volumen', 'v.2');
        $copia = \App\Models\Ejemplar::factory()->for($libro)->create(['volumen' => 'Cuadernillo', 'copia_tomo' => 1]);

        // Otra designación con su copia: no se pierde
        $this->patchJson("/api/ejemplares/{$copia->id}", ['volumen' => 'Cuadernillo c.1'])->assertOk()->assertJsonPath('etiqueta_copia', 'c.1');
        // Solo la copia: sin designación
        $this->patchJson("/api/ejemplares/{$copia->id}", ['volumen' => 'c.3'])->assertOk()->assertJsonPath('volumen', null)->assertJsonPath('etiqueta_copia', 'c.3');
        // Tomo y volumen
        $this->patchJson("/api/ejemplares/{$copia->id}", ['volumen' => 't.1-v.II c.2'])->assertOk()->assertJsonPath('tomo', 't.1-v.II')->assertJsonPath('etiqueta_copia', 'c.2');
    }
}
