<?php

namespace Tests;

use App\Support\HorarioBiblioteca;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase vacía la base entera antes de los tests (lo dispara este método).
     * Si una variable del contenedor le ganara a phpunit.xml (por ejemplo, DB_CONNECTION
     * definida en docker-compose.yml), los tests correrían contra 'biblioteca' y
     * borrarían los datos de desarrollo sin aviso: en ese caso se detienen antes.
     *
     * @return array
     */
    protected function setUpTraits()
    {
        $conexion = config('database.default');

        if ($conexion !== 'pgsql_testing') {
            $this->fail("Los tests corren contra la conexión pgsql_testing (biblioteca_test), no contra «{$conexion}»: revisa que docker-compose.yml no defina DB_CONNECTION (ver backend/README.md).");
        }

        return parent::setUpTraits();
    }

    /** A $dias de hoy, corrida al siguiente día con atención: una devolución o un retiro no puede caer en domingo. */
    protected function fechaConAtencion(int $dias): string
    {
        return HorarioBiblioteca::proximoDiaConAtencion(now()->addDays($dias))->toDateString();
    }
}
