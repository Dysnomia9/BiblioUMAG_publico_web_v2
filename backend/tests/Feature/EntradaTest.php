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

    public function test_una_visita_se_registra_sin_documento_y_cada_persona_cuenta(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/entrada/visita', ['nombre' => 'Colegio San José', 'cantidad' => 3])
            ->assertCreated()->assertJsonPath('es_visita', true);
        $this->postJson('/api/entrada/externo', [])->assertCreated();

        $this->assertSame(4, Entrada::whereNull('rut_externo')->whereNull('usuario_id')->count());
        $this->assertSame(3, Entrada::where('nombre_externo', 'Colegio San José')->where('es_visita', true)->count());
        $this->getJson('/api/entrada')->assertOk()->assertJsonPath('hoy.personas', 4);
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

    /** Una persona queda presente una sola vez por día: el segundo intento no crea otra entrada. */
    public function test_una_persona_no_queda_presente_dos_veces_el_mismo_dia(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez']);

        $this->postJson('/api/entrada', ['rut' => $usuario->rut])->assertStatus(201);
        $respuesta = $this->postJson('/api/entrada', ['rut' => $usuario->rut, 'via' => 'qr'])
            ->assertStatus(409)
            ->assertJsonPath('entrada.usuario_id', $usuario->id);
        $this->assertStringStartsWith('Ana Pérez ya marcó su entrada hoy a las', $respuesta->json('message'));

        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_la_entrada_de_ayer_no_impide_marcar_la_de_hoy(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => now()->subDay()]);

        $this->postJson('/api/entrada', ['rut' => $usuario->rut])->assertStatus(201);

        $this->assertDatabaseCount('entradas', 2);
    }

    public function test_un_externo_tampoco_queda_presente_dos_veces_aunque_escriba_el_rut_distinto(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $this->postJson('/api/entrada/externo', ['rut' => '12.345.678-5', 'nombre' => 'Visitante'])->assertStatus(201);
        $this->postJson('/api/entrada/visita', ['rut' => '12345678-5'])->assertStatus(409);

        $this->assertDatabaseCount('entradas', 1);
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

    public function test_el_resumen_de_hoy_cuenta_personas_distintas_y_como_entraron(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ana = Usuario::factory()->create();
        $beto = Usuario::factory()->create();

        $this->postJson('/api/entrada', ['rut' => $ana->rut])->assertStatus(201);
        // Una entrada repetida de antes de la regla de "una vez por día" (no se borran).
        Entrada::factory()->create(['usuario_id' => $ana->id, 'via' => 'qr']);
        $this->postJson('/api/entrada', ['rut' => $beto->rut])->assertStatus(201);
        $this->postJson('/api/entrada/visita', ['rut' => '12.345.678-5', 'nombre' => 'Delegación'])->assertStatus(201);
        Entrada::factory()->create(['via' => 'portal']);
        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDay()]);

        // Aunque se mire otro día, el recuadro es de hoy.
        $this->getJson('/api/entrada?fecha='.now()->subDay()->toDateString())->assertOk()
            ->assertJsonCount(1, 'entradas')
            ->assertJsonPath('hoy', ['personas' => 4, 'entradas' => 5, 'qr' => 1, 'portal' => 1, 'manuales' => 3, 'sala' => 0])
            ->assertJsonPath('aforo', config('biblioteca.aforo'));
    }

    public function test_la_busqueda_en_el_historial_encuentra_por_nombre_completo_sin_tildes_y_por_rut_sin_puntos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create(['nombre' => 'Sebastián', 'apellido' => 'Sánchez', 'rut' => '15.003.151-6']);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => now()->subDays(3)]);
        Entrada::factory()->create(['usuario_id' => $usuario->id, 'fecha_hora_entrada' => now()->subDay()]);
        Entrada::factory()->create(['fecha_hora_entrada' => now()->subDay()]);

        foreach (['sebastian sanchez', 'SÁNCHEZ', '150031516', '15.003'] as $busqueda) {
            $this->getJson('/api/entrada?q='.urlencode($busqueda))->assertOk()
                ->assertJsonPath('modo', 'busqueda')
                ->assertJsonCount(2, 'entradas');
        }
    }
}
