<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Los listados que crecen sin límite van por páginas, con los filtros resueltos en el servidor. */
class PaginacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_listado_de_libros_va_por_paginas_y_no_pasa_del_tope(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        Libro::factory()->count(3)->create();

        $this->getJson('/api/libros?por_pagina=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('last_page', 2);
        $this->getJson('/api/libros?por_pagina=2&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/libros?por_pagina=100000')->assertOk()->assertJsonPath('per_page', 500);
    }

    public function test_el_listado_de_prestamos_cuenta_cada_pestana_y_pagina_la_elegida(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $ana = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez']);
        $copia = Ejemplar::factory()->create();
        Prestamo::factory()->create(['usuario_id' => $ana->id, 'ejemplar_id' => $copia->id, 'estado' => 'activo', 'fecha_devolucion' => now()->addDays(3)]);
        Prestamo::factory()->create(['estado' => 'activo', 'fecha_devolucion' => now()->subDays(2)]);
        Prestamo::factory()->count(3)->create(['estado' => 'devuelto', 'fecha_devolucion_real' => now()]);

        $respuesta = $this->getJson('/api/prestamos?vista=devueltos&por_pagina=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3);
        $this->assertSame(['activos' => 2, 'vencidos' => 1, 'devueltos' => 3, 'todos' => 5], $respuesta->json('conteos'));

        $this->getJson('/api/prestamos?vista=vencidos')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/prestamos?vista=todos&q=perez')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.usuario.nombre', 'Ana')
            ->assertJsonPath('conteos.todos', 1);
    }

    public function test_la_ficha_de_una_persona_sigue_trayendo_todos_sus_prestamos(): void
    {
        Sanctum::actingAs(Staff::factory()->create());
        $usuario = Usuario::factory()->create();
        Prestamo::factory()->count(3)->create(['usuario_id' => $usuario->id]);

        $this->getJson("/api/prestamos?usuario_id={$usuario->id}")->assertOk()->assertJsonCount(3);
    }

    public function test_el_catalogo_del_portal_filtra_disponibles_y_area_en_el_servidor(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());
        $historia = Categoria::factory()->create(['nombre' => 'Historia']);
        $libre = Libro::factory()->create(['titulo' => 'Libre']);
        $libre->categorias()->attach($historia);
        Ejemplar::factory()->for($libre)->create(['estado_proceso' => 'en_estante', 'disponible' => true]);
        $prestado = Libro::factory()->create(['titulo' => 'Prestado']);
        Ejemplar::factory()->for($prestado)->create(['estado_proceso' => 'en_estante', 'disponible' => false]);

        $this->getJson('/api/mi/catalogo')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/mi/catalogo?disponibles=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Libre');
        $this->getJson("/api/mi/catalogo?categoria_id={$historia->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/mi/catalogo/areas')->assertOk()->assertJsonPath('0.nombre', 'Historia');
    }

    public function test_el_registro_absoluto_va_por_paginas_ordenado_por_fecha(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $ana = Usuario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Pérez']);
        \App\Models\Entrada::factory()->create(['usuario_id' => $ana->id, 'fecha_hora_entrada' => now()->subDays(3)]);
        Prestamo::factory()->create(['usuario_id' => $ana->id, 'fecha_prestamo' => now()->subDay(), 'libro_titulo' => 'Más reciente']);
        Prestamo::factory()->count(2)->create(['fecha_prestamo' => now()->subDays(2)]);

        $this->getJson('/api/registro-absoluto?por_pagina=2')->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'operaciones')
            ->assertJsonPath('operaciones.0.detalle', 'Más reciente');
        $this->getJson('/api/registro-absoluto?por_pagina=2&page=2')->assertOk()
            ->assertJsonPath('operaciones.1.tipo', 'entrada');
        $this->getJson('/api/registro-absoluto?q=perez')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_la_bitacora_va_por_paginas(): void
    {
        $admin = Staff::factory()->admin()->create();
        Sanctum::actingAs($admin);
        foreach (range(1, 3) as $n) {
            \App\Support\Bitacora::registrar('multas.pagar', "Pago {$n}", actor: $admin);
        }

        $this->getJson('/api/bitacora?por_pagina=2')->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'acciones');
    }
}
