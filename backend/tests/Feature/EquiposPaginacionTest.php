<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquiposPaginacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
    }

    private function inventario(): void
    {
        Equipo::factory()->count(30)->sequence(fn (Sequence $s) => [
            'codigo_inventario' => sprintf('Notebook %03d', $s->index + 1), 'tipo' => 'notebook',
        ])->create();
        Equipo::factory()->count(4)->create(['tipo' => 'notebook', 'activo' => false]);
        Equipo::factory()->count(3)->create(['tipo' => 'cargador']);
        Equipo::factory()->count(2)->create(['tipo' => 'cargador', 'activo' => false]);
    }

    public function test_el_inventario_se_recibe_en_paginas_con_totales_globales(): void
    {
        $this->inventario();
        $primera = $this->getJson('/api/equipos?page=1&activo=1')->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonPath('total', 33)
            ->assertJsonPath('per_page', 25)->assertJsonPath('last_page', 2)
            ->assertJsonPath('resumen', ['total' => 39, 'activos' => 33, 'bajas' => 6])
            ->assertJsonPath('por_categoria.cargador', 3)->assertJsonPath('por_categoria.notebook', 30);
        $segunda = $this->getJson('/api/equipos?page=2&activo=1')->assertOk()
            ->assertJsonCount(8, 'data')->assertJsonPath('current_page', 2)
            ->assertJsonPath('from', 26)->assertJsonPath('to', 33);

        $ids = [...array_column($primera->json('data'), 'id'), ...array_column($segunda->json('data'), 'id')];
        $this->assertCount(33, array_unique($ids));
        $this->assertNotContains(false, array_column($primera->json('data'), 'activo'));
        $this->assertNotContains(false, array_column($segunda->json('data'), 'activo'));
    }

    public function test_estado_y_categoria_se_filtran_antes_de_paginar(): void
    {
        $this->inventario();
        $this->getJson('/api/equipos?page=1&activo=0&tipo=notebook&por_pagina=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('total', 4)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.tipo', 'notebook')->assertJsonPath('data.0.activo', false)
            ->assertJsonPath('por_categoria.cargador', 2)->assertJsonPath('por_categoria.notebook', 4);
        $this->getJson('/api/equipos?page=1&tipo=cargador')->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('total', 5)
            ->assertJsonPath('por_categoria.cargador', 5)->assertJsonPath('por_categoria.notebook', 34);
    }

    public function test_busca_por_nombre_o_codigo_incluso_si_el_recurso_no_estaba_en_la_primera_pagina(): void
    {
        $this->inventario();
        $equipo = Equipo::where('codigo_inventario', 'Notebook 030')->sole();
        $this->getJson('/api/equipos?page=1&activo=1&q=notebook%20030')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $equipo->id)
            ->assertJsonPath('por_categoria', ['notebook' => 1]);
        $this->getJson('/api/equipos?page=1&q='.urlencode($equipo->codigo_barras))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $equipo->id);
    }

    public function test_acota_el_tamano_de_pagina_y_valida_la_pagina_solicitada(): void
    {
        $this->getJson('/api/equipos?page=1&por_pagina=10000')->assertOk()
            ->assertJsonPath('per_page', 100)->assertJsonPath('total', 0);
        $this->getJson('/api/equipos?page=-1')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/equipos?page=1&por_pagina=0')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
    }

    public function test_prestamos_conserva_la_lista_de_todos_los_recursos_activos_disponibles(): void
    {
        Equipo::factory()->count(30)->create(['tipo' => 'notebook']);
        Equipo::factory()->create(['tipo' => 'notebook', 'disponible' => false]);
        Equipo::factory()->create(['tipo' => 'notebook', 'activo' => false]);

        $this->getJson('/api/equipos?activo=1&disponible=1')->assertOk()->assertJsonCount(30)
            ->assertJsonPath('0.activo', true)->assertJsonPath('0.disponible', true);
    }

    public function test_los_contadores_se_actualizan_tras_dar_de_baja_y_reactivar(): void
    {
        $equipo = Equipo::factory()->create(['tipo' => 'notebook']);
        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => false, 'motivo' => 'Obsoleto'])->assertOk();
        $this->getJson('/api/equipos?page=1&activo=1')->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('resumen', ['total' => 1, 'activos' => 0, 'bajas' => 1]);
        $this->getJson('/api/equipos?page=1&activo=0')->assertOk()->assertJsonPath('data.0.id', $equipo->id)
            ->assertJsonPath('data.0.motivo_baja', 'Obsoleto');
        $this->patchJson("/api/equipos/{$equipo->id}/activo", ['activo' => true])->assertOk();
        $this->getJson('/api/equipos?page=1&activo=1')->assertOk()->assertJsonPath('data.0.id', $equipo->id)
            ->assertJsonPath('resumen', ['total' => 1, 'activos' => 1, 'bajas' => 0]);
    }
}
