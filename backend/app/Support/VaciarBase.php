<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Borra todas las tablas y vuelve a correr las migraciones: la base queda como recién
 * instalada, con los catálogos que crean las migraciones (roles, salas, carreras...).
 * Lo usa sistema:iniciar --vaciar; va aparte para que los tests lo reemplacen, porque
 * en MySQL borrar tablas confirma la transacción con que cada test deshace sus cambios.
 */
class VaciarBase
{
    public function __invoke(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
