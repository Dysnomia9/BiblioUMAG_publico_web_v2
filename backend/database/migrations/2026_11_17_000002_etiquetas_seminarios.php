<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['SM1' => 'Seminario 1', 'SM2' => 'Seminario 2'] as $codigo => $nombre) {
            DB::table('salas')->where('codigo', $codigo)->update(['nombre' => $nombre, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['SM1', 'SM2'] as $codigo) {
            DB::table('salas')->where('codigo', $codigo)->update(['nombre' => $codigo, 'updated_at' => now()]);
        }
    }
};
