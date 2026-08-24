<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {
        $this->command?->comment('Este seeder está deshabilitado. Usa: php artisan mockup:datos');
    }
}
