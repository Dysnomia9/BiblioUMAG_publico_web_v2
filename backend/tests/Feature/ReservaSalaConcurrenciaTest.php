<?php

namespace Tests\Feature;

use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dos reservas de sala que llegan al mismo tiempo pasaban las dos las revisiones antes
 * de que la otra se guardara, y la sala quedaba tomada dos veces (ver
 * ReservaSalaService::conSalaEIntegrantesBloqueados()). Una petición de un test no
 * compite con otra, así que esto verifica lo que lo evita: la sala y los integrantes
 * se bloquean (FOR UPDATE) antes de leer las reservas para revisarlas.
 */
class ReservaSalaConcurrenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 09:00:00'); // viernes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{sql: string, bindings: array}> */
    private function capturarSql(\Closure $accion): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        $accion();

        return $queries;
    }

    /** La primera consulta que calza con $patron, o null. */
    private function primera(array $queries, string $patron): ?array
    {
        $posicion = collect($queries)->search(fn ($q) => preg_match($patron, $q['sql']));

        return $posicion === false ? null : [...$queries[$posicion], 'posicion' => $posicion];
    }

    /**
     * Bloqueó la sala (si $salaId) y a los usuarios $usuarioIds antes de la primera
     * revisión de reservas (choque de horario, reserva pendiente, cuota): en MySQL, una
     * lectura antes de los bloqueos no vería la reserva que otra petición acaba de guardar.
     */
    private function assertBloqueaAntesDeRevisar(array $queries, ?int $salaId, array $usuarioIds): void
    {
        $revision = $this->primera($queries, '/from `reservas` where `(sala_id|fecha|estado)`/');
        $this->assertNotNull($revision, 'Se esperaba una revisión de reservas.');

        if ($salaId) {
            $sala = $this->primera($queries, '/from `salas` .*for update/');
            $this->assertNotNull($sala, 'Se esperaba bloquear la sala (FOR UPDATE).');
            $this->assertSame([$salaId], $sala['bindings']);
            $this->assertLessThan($revision['posicion'], $sala['posicion'], 'La sala se bloquea antes de revisar las reservas.');
        }

        $usuarios = $this->primera($queries, '/from `usuarios` .*for update/');
        $this->assertNotNull($usuarios, 'Se esperaba bloquear a los integrantes (FOR UPDATE).');
        // whereKey() con ids enteros los escribe en el SQL («in (3, 4)»), no como parámetros.
        preg_match('/in \(([\d, ]+)\)/', $usuarios['sql'], $ids);
        $this->assertEqualsCanonicalizing($usuarioIds, array_map('intval', explode(',', $ids[1] ?? '')));
        $this->assertLessThan($revision['posicion'], $usuarios['posicion'], 'Los integrantes se bloquean antes de revisar las reservas.');
    }

    public function test_reservar_desde_el_meson_bloquea_la_sala_y_a_los_integrantes_antes_de_revisar(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $sala = Sala::factory()->create();
        $grupo = Usuario::factory()->count(2)->create();

        $queries = $this->capturarSql(fn () => $this->postJson('/api/reservas', [
            'sala_id' => $sala->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => $grupo->pluck('rut')->all(),
        ])->assertStatus(201));

        $this->assertBloqueaAntesDeRevisar($queries, $sala->id, $grupo->pluck('id')->all());
        $this->assertSame(2, Reserva::sole()->participantes()->count());
    }

    public function test_reservar_desde_el_portal_bloquea_la_sala_y_a_los_integrantes_antes_de_revisar(): void
    {
        [$usuario, $companero] = Usuario::factory()->count(2)->create();
        Sanctum::actingAs($usuario);
        $sala = Sala::factory()->create();

        $queries = $this->capturarSql(fn () => $this->postJson('/api/mi/reservas', [
            'sala_id' => $sala->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'cantidad_personas' => 2,
            'ruts' => [$usuario->rut, $companero->rut],
        ])->assertStatus(201));

        $this->assertBloqueaAntesDeRevisar($queries, $sala->id, [$usuario->id, $companero->id]);
        $this->assertSame(2, Reserva::sole()->participantes()->count());
    }

    public function test_editar_una_reserva_bloquea_la_sala_de_destino_antes_de_revisar(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        [$logia1, $logia2] = Sala::factory()->count(2)->create();
        [$ana, $beto] = Usuario::factory()->count(2)->create();
        $reserva = Reserva::factory()->conParticipantes([$ana, $beto])->create([
            'sala_id' => $logia1->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '16:00',
            'hora_fin' => '18:00',
            'usuario_id' => $ana->id,
            'rut_usuario' => $ana->rut,
            'cantidad_personas' => 2,
        ]);

        $queries = $this->capturarSql(fn () => $this->putJson("/api/reservas/{$reserva->id}", [
            'sala_id' => $logia2->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '16:00',
            'hora_fin' => '17:00',
        ])->assertOk());

        $this->assertBloqueaAntesDeRevisar($queries, $logia2->id, [$ana->id, $beto->id]);
    }

    public function test_cambiar_integrantes_bloquea_a_quienes_quedan_en_la_reserva_antes_de_revisar(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        [$ana, $beto, $carla] = Usuario::factory()->count(3)->create();
        $reserva = Reserva::factory()->conParticipantes([$ana, $beto])->create([
            'sala_id' => Sala::factory()->create()->id,
            'fecha' => '2026-08-14',
            'hora_inicio' => '16:00',
            'hora_fin' => '18:00',
            'usuario_id' => $ana->id,
            'rut_usuario' => $ana->rut,
            'cantidad_personas' => 2,
        ]);

        $queries = $this->capturarSql(fn () => $this->putJson("/api/reservas/{$reserva->id}/integrantes", [
            'ruts' => [$ana->rut, $carla->rut],
        ])->assertOk());

        $this->assertBloqueaAntesDeRevisar($queries, null, [$ana->id, $carla->id]);
        $this->assertEqualsCanonicalizing([$ana->id, $carla->id], $reserva->participantes()->pluck('usuarios.id')->all());
    }
}
