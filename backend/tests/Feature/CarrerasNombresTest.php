<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Carreras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Los nombres de carrera se escriben bien: tildes, mayúsculas y las abreviaturas de Horizon. */
class CarrerasNombresTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function nombres(): array
    {
        $casos = [
            'DERECHO' => 'Derecho',
            'PSICOLOGIA' => 'Psicología',
            'Kinesiologia' => 'Kinesiología',
            'PEDAGOGIA EN EDUCACION PARVULARIA' => 'Pedagogía en Educación Parvularia',
            'PEDAGOGIA EN CASTELLANO Y COMUNICACION,' => 'Pedagogía en Castellano y Comunicación',
            'PEDAGOGIA EN BIOLOGIA Y CS.NATURALES' => 'Pedagogía en Biología y Ciencias Naturales',
            'Ing. Comercial' => 'Ingeniería Comercial',
            'Ing. Plan Común' => 'Ingeniería Plan Común',
            'Ing. en Comp. e Informática' => 'Ingeniería en Computación e Informática',
            'Ing. Ejec. en Adm. Empresas, Plan Especial' => 'Ingeniería de Ejecución en Administración de Empresas, Plan Especial',
            'ING. PREVENCION DE RIESGOS (P. ESPECIAL)' => 'Ingeniería Prevención de Riesgos (Plan Especial)',
            'Ped. en Educ. Diferencial' => 'Pedagogía en Educación Diferencial',
            'Plan Esp. Ped. Educ.Basica Natales' => 'Plan Especial Pedagogía en Educación Básica Natales',
            'Licenc. Educación' => 'Licenciatura en Educación',
            'Licenc. en enfermería' => 'Licenciatura en Enfermería',
            'Magis. en Cs. de la Ingeniería' => 'Magíster en Ciencias de la Ingeniería',
            'Magister Prof en Energias Renov. Y Efic. Energetica' => 'Magíster Profesional en Energías Renovables y Eficiencia Energética',
            'Doctor. Cs. Antárticas y Subantárticas' => 'Doctorado en Ciencias Antárticas y Subantárticas',
            'Téc. Niv. Sup. Enfermería' => 'Técnico de Nivel Superior Enfermería',
            'Téc. de Niv. Sup. en Administración' => 'Técnico de Nivel Superior en Administración',
            'Téc. Niv. Super. Enfermeria' => 'Técnico de Nivel Superior Enfermería',
            'Téc. Univ. Prev. Riesgos (Natales)' => 'Técnico Universitario Prevención de Riesgos (Natales)',
            'TEC.UNIV.EN INSTRUMENTACION Y AUTOMATIZACION INDUSTRIAL' => 'Técnico Universitario en Instrumentación y Automatización Industrial',
            'TECNICO EN COMERCIO EXTERIOR' => 'Técnico en Comercio Exterior',
            'Téc. Educacional (Pto. Natales)' => 'Técnico Educacional (Puerto Natales)',
            'Ing. Comercial / Téc. Niv. Super. Enfermeria' => 'Ingeniería Comercial / Técnico de Nivel Superior Enfermería',
            'Magis. en Educación /Mención Curriculo' => 'Magíster en Educación / Mención Currículo',
            // Ya bien escritas: no cambian.
            'Biología Marina' => 'Biología Marina',
            'Terapia Ocupacional' => 'Terapia Ocupacional',
            'Medicina' => 'Medicina',
        ];

        return array_map(null, array_keys($casos), array_values($casos));
    }

    #[DataProvider('nombres')]
    public function test_cada_nombre_queda_bien_escrito(string $antes, string $despues): void
    {
        $this->assertSame($despues, Carreras::normalizar($antes));
        // Y es estable: normalizar lo ya normalizado no lo cambia.
        $this->assertSame($despues, Carreras::normalizar($despues));
    }

    public function test_la_carga_masiva_escribe_bien_la_carrera_y_la_junta_con_la_que_ya_existe(): void
    {
        Sanctum::actingAs(Staff::factory()->admin()->create());
        Usuario::factory()->create(['carrera' => 'Ingeniería Comercial']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '12.345.678-5', 'nombre' => 'ANA', 'apellido' => 'PEREZ', 'carrera' => 'INGENIERIA COMERCIAL'],
            ['fila' => 3, 'rut' => '15.003.151-6', 'nombre' => 'LUIS', 'apellido' => 'ROJAS', 'carrera' => 'Ing. Comercial'],
            ['fila' => 4, 'rut' => '16.245.871-K', 'nombre' => 'EVA', 'apellido' => 'SOTO', 'carrera' => 'PEDAGOGIA EN MUSICA'],
        ]])->assertCreated();

        $this->assertSame('Ingeniería Comercial', Usuario::where('rut', '12.345.678-5')->value('carrera'));
        $this->assertSame('Ingeniería Comercial', Usuario::where('rut', '15.003.151-6')->value('carrera'));
        $this->assertSame('Pedagogía en Música', Usuario::where('rut', '16.245.871-K')->value('carrera'));
    }

    public function test_el_comando_corrige_los_usuarios_y_no_toca_nada_sin_aplicar(): void
    {
        Usuario::factory()->create(['carrera' => 'PSICOLOGIA']);
        Usuario::factory()->create(['carrera' => 'Psicología']);
        Usuario::factory()->create(['carrera' => 'Ing. Comercial']);
        $csv = tempnam(sys_get_temp_dir(), 'carreras');

        $this->artisan('usuarios:normalizar-carreras', ['--csv' => $csv])->assertSuccessful();
        $this->assertSame(1, Usuario::where('carrera', 'PSICOLOGIA')->count());

        $this->artisan('usuarios:normalizar-carreras', ['--aplicar' => true, '--csv' => $csv])->assertSuccessful();
        $this->assertSame(0, Usuario::where('carrera', 'PSICOLOGIA')->count());
        $this->assertSame(2, Usuario::where('carrera', 'Psicología')->count());
        $this->assertSame(1, Usuario::where('carrera', 'Ingeniería Comercial')->count());
        $this->assertDatabaseHas('bitacora', ['accion' => 'usuarios.normalizar_carreras']);
        @unlink($csv);
    }
}
