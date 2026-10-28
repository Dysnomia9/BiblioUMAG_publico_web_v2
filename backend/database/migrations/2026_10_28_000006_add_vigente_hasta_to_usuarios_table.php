<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las cuentas vencen: vigente_hasta es el último día en que la cuenta sirve (null: sin
     * vencimiento). Quien ya estaba activo recibe el próximo vencimiento, para que nadie
     * quede fuera de golpe el día que se despliega esto; los inactivos no cambian (siguen
     * necesitando una renovación).
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->date('vigente_hasta')->nullable()->after('activo');
            $table->index('vigente_hasta');
        });

        $vencimiento = now()->setDate(now()->year + 1, (int) config('usuarios.vigencia.mes'), (int) config('usuarios.vigencia.dia'));
        DB::table('usuarios')->where('activo', true)->update(['vigente_hasta' => $vencimiento->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropIndex(['vigente_hasta']);
            $table->dropColumn('vigente_hasta');
        });
    }
};
