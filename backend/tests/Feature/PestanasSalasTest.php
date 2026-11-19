<?php

namespace Tests\Feature;

use App\Models\PestanaSala;
use App\Models\Sala;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Las pestañas del mesón de salas se crean, ordenan, llenan y eliminan desde la pantalla. */
class PestanasSalasTest extends TestCase
{
    use RefreshDatabase;

    private function pestana(string $nombre): PestanaSala
    {
        return PestanaSala::where('nombre', $nombre)->sole();
    }

    public function test_las_pestanas_iniciales_son_las_de_siempre(): void
    {
        $this->assertSame(['Piso 1', 'Piso 2', 'Hospital', 'Administración'], PestanaSala::enOrden()->pluck('nombre')->all());
        $this->assertTrue($this->pestana('Administración')->apartada);

        $codigos = fn (string $nombre) => $this->pestana($nombre)->salas()->pluck('codigo')->sort()->values()->all();
        $this->assertSame(['HCM1', 'HCM2'], $codigos('Hospital'));
        $this->assertEqualsCanonicalizing(['SM1', 'SM2', 'TPG', 'COO', 'L09', 'ATEC'], $codigos('Administración'));
        $this->assertContains('L01', $codigos('Piso 1'));
        $this->assertNotContains('L09', $codigos('Piso 1'));
        $this->assertContains('L22', $codigos('Piso 2'));
        // Ninguna sala queda fuera.
        $this->assertTrue(Sala::whereNull('pestana_id')->doesntExist());
    }

    public function test_el_meson_recibe_las_pestanas_y_la_de_cada_sala(): void
    {
        Sanctum::actingAs(Staff::factory()->create());

        $respuesta = $this->getJson('/api/salas')->assertOk();
        $this->assertSame(['Piso 1', 'Piso 2', 'Hospital', 'Administración'], array_column($respuesta->json('pestanas'), 'nombre'));
        $hcm1 = collect($respuesta->json('salas'))->firstWhere('codigo', 'HCM1');
        $this->assertSame($this->pestana('Hospital')->id, $hcm1['pestana_id']);
    }

    public function test_administracion_salas_trae_las_pestanas_para_gestionarlas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $respuesta = $this->getJson('/api/mantenedor-salas')->assertOk();
        $this->assertSame(['Piso 1', 'Piso 2', 'Hospital', 'Administración'], array_column($respuesta->json('pestanas'), 'nombre'));
        $hcm1 = collect($respuesta->json('salas'))->firstWhere('codigo', 'HCM1');
        $this->assertSame($this->pestana('Hospital')->id, $hcm1['pestana_id']);
    }

    public function test_se_crea_una_pestana_y_se_le_pasan_salas(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());

        $this->postJson('/api/pestanas-salas', ['nombre' => 'Subterráneo'])->assertCreated();
        $nueva = $this->pestana('Subterráneo');
        // Va después de las de siempre y antes de las apartadas.
        $this->assertSame(['Piso 1', 'Piso 2', 'Hospital', 'Subterráneo', 'Administración'], PestanaSala::enOrden()->pluck('nombre')->all());

        $l01 = Sala::where('codigo', 'L01')->sole();
        $this->putJson("/api/pestanas-salas/{$nueva->id}/salas", ['sala_ids' => [$l01->id]])->assertOk();
        $this->assertSame($nueva->id, $l01->fresh()->pestana_id);

        // Quitarla de la pestaña la deja en «Otras».
        $this->putJson("/api/pestanas-salas/{$nueva->id}/salas", ['sala_ids' => []])->assertOk();
        $this->assertNull($l01->fresh()->pestana_id);
    }

    public function test_se_renombra_y_se_reordena(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $hospital = $this->pestana('Hospital');

        $this->putJson("/api/pestanas-salas/{$hospital->id}", ['nombre' => 'Hospital Clínico'])->assertOk();
        $this->putJson("/api/pestanas-salas/{$hospital->id}", ['nombre' => 'Piso 1'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/api/pestanas-salas', ['nombre' => 'Otras'])->assertUnprocessable();

        $ids = PestanaSala::enOrden()->pluck('id', 'nombre');
        $this->putJson('/api/pestanas-salas/orden', ['ids' => [$ids['Hospital Clínico'], $ids['Piso 1'], $ids['Piso 2'], $ids['Administración']]])->assertOk();
        $this->assertSame(['Hospital Clínico', 'Piso 1', 'Piso 2', 'Administración'], PestanaSala::enOrden()->pluck('nombre')->all());
    }

    public function test_eliminar_una_pestana_deja_sus_salas_en_otras(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        $hospital = $this->pestana('Hospital');

        $this->deleteJson("/api/pestanas-salas/{$hospital->id}")->assertOk();

        $this->assertNull(PestanaSala::find($hospital->id));
        $this->assertSame(2, Sala::whereIn('codigo', ['HCM1', 'HCM2'])->whereNull('pestana_id')->count());
    }

    public function test_sin_permiso_de_catalogos_no_se_gestionan(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());

        $this->postJson('/api/pestanas-salas', ['nombre' => 'Nueva'])->assertForbidden();
        $this->deleteJson("/api/pestanas-salas/{$this->pestana('Hospital')->id}")->assertForbidden();
    }
}
