<?php

namespace Tests\Feature;

use App\Models\Autor;
use App\Models\Libro;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListadoOrdenTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_ordena_por_autor_dewey_y_al_reves(): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $a = Libro::factory()->create(['titulo' => 'A', 'clasificacion' => '900 Z']);
        $b = Libro::factory()->create(['titulo' => 'B', 'clasificacion' => '100 A']);
        $c = Libro::factory()->create(['titulo' => 'C', 'clasificacion' => null]);
        $a->autores()->attach(Autor::create(['nombre' => 'Zúñiga, Ana'])->id, ['orden' => 1]);
        $b->autores()->attach(Autor::create(['nombre' => 'Álvarez, Beto'])->id, ['orden' => 1]);

        $ids = fn (string $q) => collect($this->getJson("/api/libros?{$q}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$b->id, $a->id, $c->id], $ids('orden=autor'));
        $this->assertSame([$b->id, $a->id, $c->id], $ids('orden=clasificacion'));
        $this->assertSame([$a->id, $b->id, $c->id], $ids('orden=clasificacion&direccion=desc'));
        $this->assertSame([$c->id, $b->id, $a->id], $ids('orden=titulo&direccion=desc'));
    }
}
