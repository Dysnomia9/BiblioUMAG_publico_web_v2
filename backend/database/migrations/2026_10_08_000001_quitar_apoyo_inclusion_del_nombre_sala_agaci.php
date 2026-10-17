<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La sala se llama simplemente «Sala AGACI»: se quita el «(Apoyo a la Inclusión)»
 * que traía el nombre sembrado por mockup:datos en instalaciones existentes (el
 * seeder, SeedMockupData::seedSalas(), ya genera el nombre corto). El reemplazo se
 * hace en PHP para que sirva igual en PostgreSQL y en MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salas')->where('nombre', 'like', '%Apoyo a la Inclusi%')->get(['id', 'nombre'])
            ->each(fn ($sala) => DB::table('salas')->where('id', $sala->id)->update([
                'nombre' => trim(preg_replace('/\s*\(Apoyo a la Inclusi[oó]n\)/iu', '', $sala->nombre)),
            ]));
    }

    public function down(): void
    {
        DB::table('salas')
            ->where('nombre', 'Sala AGACI')
            ->update(['nombre' => 'Sala AGACI (Apoyo a la Inclusión)']);
    }
};
