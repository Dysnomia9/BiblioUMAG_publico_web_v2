<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reemplaza los bloques fijos de 2 horas (hora_inicio/hora_fin como integer, 8..21)
// por horario continuo: inicio libre + duración de hasta 2 horas, columnas `time`.
// Las reservas existentes (ej. 14) quedan como 14:00:00, sin pérdida de información —
// ver spec "reserva de salas con horario continuo".
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reservas ALTER COLUMN hora_inicio TYPE time USING make_time(hora_inicio, 0, 0)');
        DB::statement('ALTER TABLE reservas ALTER COLUMN hora_fin TYPE time USING make_time(hora_fin, 0, 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservas ALTER COLUMN hora_inicio TYPE integer USING EXTRACT(HOUR FROM hora_inicio)::integer');
        DB::statement('ALTER TABLE reservas ALTER COLUMN hora_fin TYPE integer USING EXTRACT(HOUR FROM hora_fin)::integer');
    }
};
