<?php

use App\Support\TomoEjemplar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tomo y copia del tomo, separados (en Horizon iban juntos: «v.1 c.2»). Se llenan desde el
 * volumen que trajo el ETL; el volumen queda igual. La reserva del portal también guarda
 * el tomo pedido, para que la lista de espera aparte una copia de ese tomo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('tomo', 50)->nullable()->after('volumen');
            $table->unsignedSmallInteger('copia_tomo')->nullable()->after('tomo');
            $table->index(['libro_id', 'tomo']);
        });
        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->string('tomo', 50)->nullable()->after('ejemplar_id');
        });

        DB::table('ejemplares')->whereNotNull('volumen')->where('volumen', '<>', '')
            ->select(['id', 'volumen'])->orderBy('id')
            ->chunkById(2000, function ($filas) {
                foreach ($filas as $fila) {
                    [$tomo, $copia] = TomoEjemplar::desdeEtiqueta($fila->volumen);
                    if ($tomo !== null) {
                        DB::table('ejemplares')->where('id', $fila->id)->update(['tomo' => $tomo, 'copia_tomo' => $copia]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('reservas_libro', fn (Blueprint $table) => $table->dropColumn('tomo'));
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropIndex(['libro_id', 'tomo']);
            $table->dropColumn(['tomo', 'copia_tomo']);
        });
    }
};
