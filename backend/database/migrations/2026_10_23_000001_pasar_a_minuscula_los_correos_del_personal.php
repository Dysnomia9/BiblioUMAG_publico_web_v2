<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Desde ahora el correo del personal se guarda en minúscula (Staff::email()) y el
     * login lo busca así: las cuentas creadas antes con mayúsculas ("Ana@umag.cl") se
     * pasan a minúscula para que sigan entrando. Si dos cuentas quedarían con el mismo
     * correo, no se tocan: se corrige a mano en "Personal y roles".
     */
    public function up(): void
    {
        $correos = DB::table('staff')->pluck('email', 'id');
        $repetidos = $correos->map(fn (string $correo) => Str::lower($correo))->duplicates();

        foreach ($correos as $id => $correo) {
            $minuscula = Str::lower($correo);
            if ($correo !== $minuscula && ! $repetidos->contains($minuscula)) {
                DB::table('staff')->where('id', $id)->update(['email' => $minuscula]);
            }
        }
    }

    public function down(): void
    {
        // No se sabe cómo estaba escrito cada correo: queda en minúscula.
    }
};
