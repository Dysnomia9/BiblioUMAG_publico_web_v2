<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La migración desde Horizon dejaba «no disponible» toda copia que no estuviera en
 * estante (inventario, estantería auxiliar, procesos técnicos…), aunque nadie la tuviera.
 * Acá disponible dice solo si la copia está prestada o apartada para una reserva: al
 * pasarla a «En estante» no se podía prestar («ya está reservado/prestado por otra
 * persona»). Se liberan las que no tienen préstamo activo ni reserva pendiente. Las dadas
 * de baja no se tocan: una perdida sigue no disponible hasta reincorporarla
 * (BajaEjemplarService::reincorporar()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE ejemplares e SET disponible = true, updated_at = now()
            WHERE e.disponible = false
              AND e.estado_proceso <> 'de_baja'
              AND NOT EXISTS (SELECT 1 FROM prestamos p WHERE p.ejemplar_id = e.id AND p.estado = 'activo')
              AND NOT EXISTS (SELECT 1 FROM reservas_libro r WHERE r.ejemplar_id = e.id AND r.estado = 'pendiente')
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: no queda registro de cuáles venían así desde Horizon.
    }
};
