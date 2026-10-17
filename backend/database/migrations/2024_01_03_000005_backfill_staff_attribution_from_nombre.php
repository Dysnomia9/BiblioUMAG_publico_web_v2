<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migración de datos (DML), separada de la de esquema (DDL) a propósito
     * para poder revertir el backfill sin tocar las columnas. Match exacto
     * por nombre contra staff.nombre; filas sin match quedan NULL (aceptable
     * para historial ya escrito con nombres libres/mal tipeados).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE prestamos
            JOIN staff ON staff.nombre = prestamos.prestado_por
            SET prestamos.prestado_por_staff_id = staff.id
            WHERE prestamos.prestado_por_staff_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE prestamos
            JOIN staff ON staff.nombre = prestamos.devuelto_por
            SET prestamos.devuelto_por_staff_id = staff.id
            WHERE prestamos.devuelto_por_staff_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE prestamos
            JOIN staff ON staff.nombre = prestamos.multa_pagada_por
            SET prestamos.multa_pagada_por_staff_id = staff.id
            WHERE prestamos.multa_pagada_por_staff_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::table('prestamos')->update([
            'prestado_por_staff_id' => null,
            'devuelto_por_staff_id' => null,
            'multa_pagada_por_staff_id' => null,
        ]);
    }
};
