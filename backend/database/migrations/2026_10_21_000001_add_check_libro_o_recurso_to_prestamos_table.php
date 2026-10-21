<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Un préstamo es de un libro (ejemplar_id) o de un recurso de apoyo (equipo_id),
     * nunca de los dos, y apunta a lo de su tipo: el de libro no lleva equipo_id y el de
     * recurso no lleva ejemplar_id. Hasta ahora solo lo cuidaba PrestamoController::store().
     *
     * No exige que la copia o el recurso estén: los préstamos de antes de que existieran
     * los ejemplares o los equipos pueden no tener ninguno, y siguen siendo historial.
     */
    public function up(): void
    {
        // MySQL solo diría que la restricción no se cumple: acá se dice cuáles.
        $invalidos = DB::table('prestamos')
            ->where(fn ($q) => $q
                ->where(fn ($libro) => $libro->where('tipo_item', 'libro')->whereNotNull('equipo_id'))
                ->orWhere(fn ($recurso) => $recurso->where('tipo_item', '!=', 'libro')->whereNotNull('ejemplar_id')))
            ->pluck('id');

        if ($invalidos->isNotEmpty()) {
            throw new RuntimeException('Préstamos que apuntan a una copia y a un recurso a la vez, o a lo que no es de su tipo (ids '.$invalidos->join(', ').'): corrígelos antes de migrar.');
        }

        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_libro_o_recurso CHECK ((tipo_item = 'libro' AND equipo_id IS NULL) OR (tipo_item <> 'libro' AND ejemplar_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_libro_o_recurso');
    }
};
