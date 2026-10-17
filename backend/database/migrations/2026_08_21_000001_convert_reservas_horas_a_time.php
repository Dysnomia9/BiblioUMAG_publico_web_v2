<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reemplaza los bloques fijos de 2 horas (hora_inicio/hora_fin como integer, 8..21)
// por horario continuo: inicio libre + duración de hasta 2 horas, columnas `time`.
// Las reservas existentes (ej. 14) quedan como 14:00:00, sin pérdida de información.
//
// MySQL convierte el entero 14 a TIME como 00:00:14 (lo lee como HHMMSS); por eso,
// después de cambiar el tipo, los segundos se pasan a horas.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hora_inicio', 'hora_fin'] as $columna) {
            DB::statement("ALTER TABLE reservas MODIFY {$columna} TIME NOT NULL");
            DB::statement("UPDATE reservas SET {$columna} = SEC_TO_TIME(TIME_TO_SEC({$columna}) * 3600)");
        }
    }

    public function down(): void
    {
        foreach (['hora_inicio', 'hora_fin'] as $columna) {
            DB::statement("UPDATE reservas SET {$columna} = SEC_TO_TIME(HOUR({$columna}))");
            DB::statement("ALTER TABLE reservas MODIFY {$columna} TINYINT UNSIGNED NOT NULL");
        }
    }
};
