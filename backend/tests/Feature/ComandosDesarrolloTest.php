<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ComandosDesarrolloTest extends TestCase
{
    public function test_en_pruebas_se_pueden_cargar_datos_sin_opcion_de_forzar_produccion(): void
    {
        $comandos = Artisan::all();

        $this->assertArrayHasKey('mockup:datos', $comandos);
        $this->assertFalse($comandos['mockup:datos']->getDefinition()->hasOption('force'));
        $this->assertArrayNotHasKey('benchmark:api', $comandos);
    }

    public function test_produccion_y_staging_no_registran_comandos_de_demostracion(): void
    {
        foreach (['production', 'staging'] as $entorno) {
            $proceso = new Process([PHP_BINARY, base_path('artisan'), 'list', '--raw'], base_path(), ['APP_ENV' => $entorno]);
            $proceso->mustRun();

            $this->assertDoesNotMatchRegularExpression('/^mockup:datos\s/m', $proceso->getOutput(), $entorno);
            $this->assertDoesNotMatchRegularExpression('/^benchmark:api\s/m', $proceso->getOutput(), $entorno);
            $this->assertMatchesRegularExpression('/^sistema:iniciar\s/m', $proceso->getOutput(), $entorno);
        }
    }

    public function test_desarrollo_registra_la_carga_de_datos(): void
    {
        $proceso = new Process([PHP_BINARY, base_path('artisan'), 'list', '--raw'], base_path(), ['APP_ENV' => 'local']);
        $proceso->mustRun();

        $this->assertMatchesRegularExpression('/^mockup:datos\s/m', $proceso->getOutput());
    }
}
