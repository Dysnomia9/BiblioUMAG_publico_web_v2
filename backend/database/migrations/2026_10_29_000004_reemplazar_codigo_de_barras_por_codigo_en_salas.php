<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las logias ya no se identifican con los códigos de barras de Horizon (nunca llegaron los
 * reales): cada sala tiene un código propio y corto, el que va en la puerta y se escribe en
 * el mesón para entregar o recibir la llave, y con el que se siguen en Reportes. Las logias
 * van por su número (Logia 04 → L04) y las salas con nombre propio, por una abreviatura.
 */
return new class extends Migration
{
    private const ESPECIALES = ['Sala de Seminarios' => 'SEM', 'Sala de Postgrado' => 'POS', 'Sala AGACI' => 'AGA'];

    public function up(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->string('codigo', 10)->nullable()->after('nombre');
        });

        foreach (DB::table('salas')->orderBy('id')->get(['id', 'nombre']) as $sala) {
            $codigo = preg_match('/^Logia\s+0*(\d+)$/i', trim($sala->nombre), $m)
                ? 'L'.str_pad($m[1], 2, '0', STR_PAD_LEFT)
                : (self::ESPECIALES[$sala->nombre] ?? 'S'.str_pad((string) $sala->id, 2, '0', STR_PAD_LEFT));
            DB::table('salas')->where('id', $sala->id)->update(['codigo' => $codigo]);
        }

        Schema::table('salas', function (Blueprint $table) {
            $table->string('codigo', 10)->nullable(false)->unique()->change();
            $table->dropColumn('codigo_barras');
        });
    }

    public function down(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->string('codigo_barras')->nullable()->unique();
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
