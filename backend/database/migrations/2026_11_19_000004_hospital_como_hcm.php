<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La pestaña del mesón «Hospital» pasa a llamarse «HCM» (Hospital Clínico de Magallanes), si
 * nadie la renombró antes. Las salas no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('pestanas_salas')->where('nombre', 'HCM')->exists()) {
            DB::table('pestanas_salas')->where('nombre', 'Hospital')->update(['nombre' => 'HCM', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! DB::table('pestanas_salas')->where('nombre', 'Hospital')->exists()) {
            DB::table('pestanas_salas')->where('nombre', 'HCM')->update(['nombre' => 'Hospital', 'updated_at' => now()]);
        }
    }
};
