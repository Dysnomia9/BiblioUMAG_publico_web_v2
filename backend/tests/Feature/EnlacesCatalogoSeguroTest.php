<?php

namespace Tests\Feature;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use App\Support\Enlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los enlaces del catálogo (856) van a un href en la ficha y en el portal: solo se aceptan
 * y se muestran como vínculo las direcciones http o https. «javascript://%0a…» pasaba
 * FILTER_VALIDATE_URL y «data://…» la regla «url» de Laravel; con un clic, ejecutaban código.
 */
class EnlacesCatalogoSeguroTest extends TestCase
{
    use RefreshDatabase;

    private const PELIGROSOS = [
        'javascript://%0aalert(document.domain)',
        'JavaScript://comentario%0Aalert(1)',
        'data://text/html,<script>alert(1)</script>',
        'vbscript://x',
        'file:///etc/passwd',
        'http:sin-servidor',
    ];

    public function test_solo_las_direcciones_web_son_enlaces(): void
    {
        foreach (self::PELIGROSOS as $url) {
            $this->assertFalse(Enlace::esWeb($url), $url);
        }
        $this->assertTrue(Enlace::esWeb('https://ebsco.example/libro?id=1'));
        $this->assertTrue(Enlace::esWeb('HTTP://biblioteca.umag.cl'));
    }

    public function test_al_catalogar_se_rechazan_los_enlaces_que_no_son_web(): void
    {
        $libro = Libro::factory()->create();
        Sanctum::actingAs(Staff::factory()->create(['rol' => 'admin']));

        foreach (self::PELIGROSOS as $url) {
            $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'url_acceso' => $url])
                ->assertStatus(422)->assertJsonValidationErrors('url_acceso');
            $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'otros_enlaces' => "https://ok.example\n{$url} Tomo 2"])
                ->assertStatus(422)->assertJsonValidationErrors('otros_enlaces');
        }

        $this->patchJson("/api/libros/{$libro->id}", ['titulo' => $libro->titulo, 'url_acceso' => 'https://ok.example/libro'])->assertOk();
    }

    public function test_un_enlace_guardado_que_no_es_web_se_muestra_como_texto(): void
    {
        // Como llegaría desde Horizon, sin pasar por la validación.
        $libro = Libro::factory()->create([
            'otros_enlaces' => "javascript://%0aalert(1) Tomo 1\nhttps://drive.example/v2 Tomo 2",
        ]);
        Ejemplar::factory()->for($libro)->create();
        Sanctum::actingAs(Staff::factory()->create());

        $ficha = collect($this->getJson('/api/libros')->assertOk()->json('data.0.ficha'))->keyBy('campo');

        $this->assertSame([
            ['texto' => 'javascript://%0aalert(1) Tomo 1'],
            ['texto' => 'Tomo 2', 'url' => 'https://drive.example/v2'],
        ], $ficha['Otros enlaces']['items']);
    }

    public function test_el_portal_no_entrega_un_libro_electronico_con_enlace_que_no_es_web(): void
    {
        $tipo = TipoMaterial::firstOrCreate(['nombre' => 'Libro electrónico']);
        $libro = Libro::factory()->create(['titulo' => 'Ebook raro', 'tipo_material_id' => $tipo->id, 'url_acceso' => 'data://text/html,x']);
        Ejemplar::factory()->for($libro)->create(['estado_proceso' => 'en_estante']);

        Sanctum::actingAs(Usuario::factory()->create());
        $fila = collect($this->getJson('/api/mi/catalogo?q=ebook')->assertOk()->json('data'))->firstWhere('id', $libro->id);

        $this->assertNotNull($fila);
        $this->assertFalse($fila['digital']);
        $this->assertNull($fila['url_acceso']);
    }
}
