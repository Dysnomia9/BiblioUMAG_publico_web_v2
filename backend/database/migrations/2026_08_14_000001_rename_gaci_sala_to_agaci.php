<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La Sala GACI (Apoyo a la Inclusión) pasó a llamarse AGACI — corrige el
 * nombre ya sembrado por mockup:datos en instalaciones existentes. El
 * seeder (SeedMockupData::seedSalas()) ya se actualiza por separado para que
 * futuros --fresh generen "AGACI" directamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salas')
            ->where('nombre', 'like', '%GACI%')
            ->where('nombre', 'not like', '%AGACI%')
            ->update(['nombre' => DB::raw("replace(nombre, 'GACI', 'AGACI')")]);
    }

    public function down(): void
    {
        DB::table('salas')
            ->where('nombre', 'like', '%AGACI%')
            ->update(['nombre' => DB::raw("replace(nombre, 'AGACI', 'GACI')")]);
    }
};
