<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Rut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EntradaTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_entrada_con_rut_inexistente_devuelve_404(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $rutInexistente = Rut::formatear(11111111);

        $response = $this->postJson('/api/entrada', ['rut' => $rutInexistente]);

        $response->assertStatus(404);
    }

    public function test_post_entrada_externo_devuelve_201_y_crea_entrada_con_rut_externo(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $rutExterno = Rut::formatear(9999999);

        $response = $this->postJson('/api/entrada/externo', ['rut' => $rutExterno]);

        $response->assertStatus(201)
            ->assertJsonPath('rut_externo', $rutExterno);

        $this->assertDatabaseHas('entradas', ['rut_externo' => $rutExterno]);
    }

    public function test_post_entrada_visita_devuelve_201_y_marca_es_visita(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $rutVisita = Rut::formatear(9888888);

        $response = $this->postJson('/api/entrada/visita', ['rut' => $rutVisita, 'nombre' => 'Visitante de Prueba']);

        $response->assertStatus(201)
            ->assertJsonPath('rut_externo', $rutVisita)
            ->assertJsonPath('es_visita', true);

        $this->assertDatabaseHas('entradas', ['rut_externo' => $rutVisita, 'es_visita' => true]);
    }

    /**
     * Horizon (el sistema legado) no registra un evento de salida por separado: la
     * entrada queda cerrada en el mismo instante en que se registra. Antes esto se
     * dejaba en null hasta que alguien la cerrara a mano, y una entrada sin cerrar de
     * días anteriores bloqueaba (409) cualquier ingreso nuevo de esa persona.
     */
    public function test_post_entrada_marca_fecha_hora_salida_de_inmediato(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();

        $response = $this->postJson('/api/entrada', ['rut' => $usuario->rut]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('fecha_hora_salida'));

        $entrada = Entrada::first();
        $this->assertNotNull($entrada->fecha_hora_salida);
        $this->assertTrue($entrada->fecha_hora_entrada->equalTo($entrada->fecha_hora_salida));
    }

    public function test_registrar_entrada_dos_veces_el_mismo_dia_ya_no_se_bloquea(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();

        $this->postJson('/api/entrada', ['rut' => $usuario->rut])->assertStatus(201);
        $this->postJson('/api/entrada', ['rut' => $usuario->rut])->assertStatus(201);

        $this->assertDatabaseCount('entradas', 2);
    }

    /**
     * personasEnSala debe contar solo las entradas de la fecha consultada, no
     * arrastrar entradas de días anteriores (aunque nunca se borren de la DB).
     */
    public function test_personas_en_sala_no_arrastra_entradas_de_dias_anteriores(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Entrada::factory()->create([
            'fecha_hora_entrada' => now()->subDay(),
            'fecha_hora_salida' => now()->subDay(),
        ]);
        Entrada::factory()->create([
            'fecha_hora_entrada' => now(),
            'fecha_hora_salida' => now(),
        ]);

        $response = $this->getJson('/api/entrada?fecha='.now()->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('personasEnSala', 1)
            ->assertJsonCount(1, 'entradas');
    }

    public function test_busqueda_por_rango_de_fechas_incluye_varios_dias(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDays(5), 'fecha_hora_salida' => now()->subDays(5)]);
        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDays(2), 'fecha_hora_salida' => now()->subDays(2)]);
        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDays(10), 'fecha_hora_salida' => now()->subDays(10)]);

        $response = $this->getJson('/api/entrada?desde='.now()->subDays(3)->toDateString().'&hasta='.now()->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('modo', 'busqueda')
            ->assertJsonCount(1, 'entradas');
    }

    public function test_busqueda_por_rut_encuentra_entradas_de_un_usuario_registrado(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $usuario = Usuario::factory()->create();
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => now()->subDays(20)]);
        Entrada::factory()->create();

        $response = $this->getJson('/api/entrada?q='.urlencode($usuario->rut));

        $response->assertStatus(200)
            ->assertJsonPath('modo', 'busqueda')
            ->assertJsonCount(1, 'entradas')
            ->assertJsonPath('entradas.0.usuario.rut', $usuario->rut);
    }

    public function test_busqueda_por_nombre_de_externo_no_distingue_mayusculas(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Entrada::factory()->create(['usuario_id' => null, 'rut_externo' => Rut::formatear(7777777), 'nombre_externo' => 'Pedro Pascal']);
        Entrada::factory()->create(['usuario_id' => null, 'rut_externo' => Rut::formatear(6666666), 'nombre_externo' => 'Otra Persona']);

        $response = $this->getJson('/api/entrada?q=pascal');

        $response->assertStatus(200)->assertJsonCount(1, 'entradas');
    }

    public function test_busqueda_vacia_sin_desde_hasta_ni_q_usa_el_modo_dia_por_defecto(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        Entrada::factory()->create(['fecha_hora_entrada' => now(), 'fecha_hora_salida' => now()]);

        $response = $this->getJson('/api/entrada');

        $response->assertStatus(200)->assertJsonPath('modo', 'dia');
    }
}
