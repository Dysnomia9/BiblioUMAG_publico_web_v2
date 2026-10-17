<?php

namespace Tests;

use App\Support\HorarioBiblioteca;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A $dias de hoy, corrida al siguiente día con atención: una devolución o un retiro no puede caer en domingo. */
    protected function fechaConAtencion(int $dias): string
    {
        return HorarioBiblioteca::proximoDiaConAtencion(now()->addDays($dias))->toDateString();
    }
}
