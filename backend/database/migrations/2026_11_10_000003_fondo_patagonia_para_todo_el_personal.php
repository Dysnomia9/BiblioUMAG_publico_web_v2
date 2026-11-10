<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patagonia pasa a ser el fondo del modo día de todo el personal (FONDO_PREDETERMINADO en
 * frontend/src/utils/fondos.ts). Se vacía el que tenían elegido las cuentas que ya existían,
 * así quedan con el predeterminado; cada persona puede volver a elegir otro en Aspecto
 * visual. No toca updated_at: no es una edición de la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('staff')->whereNotNull('fondo')->update(['fondo' => null]);
    }

    public function down(): void
    {
        // Lo que cada cuenta tenía elegido no se guardó: no hay cómo volver atrás.
    }
};
