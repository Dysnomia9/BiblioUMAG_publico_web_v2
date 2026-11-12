<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lo que manda alguien que arma la solicitud a mano (DevTools, curl): un texto donde va un
 * número o una lista donde va un texto. Tiene que dar 404 o 422, nunca un error 500.
 */
class EntradasMalFormadasTest extends TestCase
{
    use RefreshDatabase;

    public static function delPortal(): array
    {
        return [
            'id de reserva con letras' => ['DELETE', '/api/mi/reservas/abc', 404],
            'salirse de una reserva con letras' => ['PATCH', '/api/mi/reservas/abc/salir', 404],
            'id de reserva de libro con letras' => ['PATCH', '/api/mi/reservas-libro/abc/cancelar', 404],
            'búsqueda como lista' => ['GET', '/api/mi/catalogo?q[]=a', 422],
            'área con letras' => ['GET', '/api/mi/catalogo?categoria_id=abc', 422],
            'áreas: búsqueda como lista' => ['GET', '/api/mi/catalogo/areas?q[]=a', 422],
        ];
    }

    #[DataProvider('delPortal')]
    public function test_el_portal_no_responde_500(string $metodo, string $url, int $esperado): void
    {
        Sanctum::actingAs(Usuario::factory()->create());

        $this->json($metodo, $url)->assertStatus($esperado);
    }

    public static function delPersonal(): array
    {
        return [
            'búsqueda como lista' => ['/api/libros?q[]=a'],
            'materia como lista' => ['/api/libros?materia[]=a'],
            'categoría con letras' => ['/api/libros?categoria_id=abc'],
            'autor con letras' => ['/api/libros?autor_id=abc'],
        ];
    }

    #[DataProvider('delPersonal')]
    public function test_el_listado_de_material_no_responde_500(string $url): void
    {
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        $this->getJson($url)->assertStatus(422);
    }

    public function test_lo_bien_escrito_sigue_funcionando(): void
    {
        Sanctum::actingAs(Usuario::factory()->create());
        $this->getJson('/api/mi/catalogo?q=calculo&categoria_id=1')->assertOk();
        $this->getJson('/api/mi/reservas-libro')->assertOk();

        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));
        $this->getJson('/api/libros?q=calculo&categoria_id=&autor_id=3')->assertOk();
    }
}
