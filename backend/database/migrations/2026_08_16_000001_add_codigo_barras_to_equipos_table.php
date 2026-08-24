<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los equipos (audífonos/notebooks/cargadores) se prestaban identificándose por
 * codigo_inventario en texto libre — en la práctica el préstamo real se hace
 * escaneando un código de barras físico, igual que un libro. Se agrega
 * codigo_barras como el campo que realmente se escanea/tipea al prestar;
 * codigo_inventario se mantiene como el identificador legible ("Notebook 01").
 * Los códigos reales de los equipos no estaban disponibles al implementar esto —
 * el backfill usa un placeholder largo (mismo criterio que horizon_barcodes.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->string('codigo_barras')->nullable()->after('codigo_inventario');
        });

        DB::table('equipos')->orderBy('id')->chunkById(50, function ($equipos) {
            foreach ($equipos as $equipo) {
                DB::table('equipos')->where('id', $equipo->id)->update([
                    'codigo_barras' => '750'.str_pad((string) $equipo->id, 10, '0', STR_PAD_LEFT),
                ]);
            }
        });

        Schema::table('equipos', function (Blueprint $table) {
            $table->string('codigo_barras')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->dropColumn('codigo_barras');
        });
    }
};
