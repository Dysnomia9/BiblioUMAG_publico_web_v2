<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Si un préstamo está atrasado se calcula con su fecha de devolución
     * (Prestamo::scopeVencidos()), no se guarda. El estado 'atrasado' solo lo escribían
     * los datos de prueba, una vez al cargarse, y las pantallas igual miraban la fecha:
     * había dos fuentes para lo mismo, y no siempre coincidían. Los que estaban así
     * vuelven a 'activo' (su fecha sigue diciendo que están atrasados) y la base deja de
     * aceptarlo: el estado dice solo si se devolvió.
     */
    public function up(): void
    {
        DB::table('prestamos')->where('estado', 'atrasado')->update(['estado' => 'activo']);

        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_estado');
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_estado CHECK (estado IN ('activo','devuelto'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_estado');
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_estado CHECK (estado IN ('activo','devuelto','atrasado'))");
    }
};
