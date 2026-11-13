<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevisarMateriasTest extends TestCase
{
    use RefreshDatabase;

    public function test_avisa_si_la_materia_ya_existe_escrita_de_otra_forma(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $rapaNui = Categoria::create(['nombre' => 'RAPA NUI']);
        Libro::factory()->create()->categorias()->attach($rapaNui);
        Categoria::create(['nombre' => 'INDIGENAS DE CHILE']);
        Categoria::create(['nombre' => 'AÑOS']);

        $this->postJson('/api/categorias/revisar', ['nombres' => [
            'RAPANUI', 'INDIGENAS DE CHILE', 'Indígenas de Chile', 'Quechua -- Lenguas -- Diccionarios', 'ANOS', 'RAPANUI -- LENGUAS -- DICCIONARIOS',
        ]])
            ->assertOk()
            ->assertJsonPath('0.tipo', 'parecida')
            ->assertJsonPath('0.existente.nombre', 'RAPA NUI')
            ->assertJsonPath('0.existente.libros', 1)
            ->assertJsonPath('1.tipo', 'exacta')
            ->assertJsonPath('2.tipo', 'igual')
            ->assertJsonPath('2.existente.nombre', 'INDIGENAS DE CHILE')
            ->assertJsonPath('3.tipo', 'nueva')
            ->assertJsonPath('3.existente', null)
            // La ñ no se iguala a la n, igual que al guardar.
            ->assertJsonPath('4.tipo', 'nueva')
            ->assertJsonPath('3.sugerencia', null)
            // Nueva entera, pero su tema ya existe escrito de otra forma.
            ->assertJsonPath('5.tipo', 'nueva')
            ->assertJsonPath('5.sugerencia.tema_existente', 'RAPA NUI')
            ->assertJsonPath('5.sugerencia.nombre', 'RAPA NUI -- LENGUAS -- DICCIONARIOS');
    }
}
