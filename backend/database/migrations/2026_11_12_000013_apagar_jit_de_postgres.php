<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * JIT de Postgres apagado para esta base. Compila a código de máquina cada consulta cuyo
     * costo estimado pasa de 100.000, y los listados del catálogo (46 mil títulos, con
     * «exists» y conteos) lo pasan siempre: en el catálogo del portal compilar costaba
     * 23 + 48 ms y no ganaba nada (conteo 90 → 45 ms y página 170 → 90 ms sin JIT). JIT sirve
     * para consultas analíticas de segundos o minutos, no para las de una aplicación web.
     * Vale para las conexiones nuevas; se aplica a la base (el usuario de la aplicación es
     * su dueño), no al servidor.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER DATABASE '.$this->base().' SET jit = off');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER DATABASE '.$this->base().' RESET jit');
    }

    private function base(): string
    {
        return '"'.str_replace('"', '""', DB::selectOne('select current_database() as nombre')->nombre).'"';
    }
};
