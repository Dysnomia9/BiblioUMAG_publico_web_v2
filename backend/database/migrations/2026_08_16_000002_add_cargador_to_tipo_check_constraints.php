<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega "cargador" como tercer tipo de equipo prestable (junto a audífonos y
 * notebooks) — Postgres no permite alterar un CHECK existente, hay que dropearlo
 * y recrearlo con el valor nuevo. Afecta a equipos.tipo y prestamos.tipo_item (el
 * mismo valor se guarda en ambas columnas al crear un préstamo de equipo).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE equipos DROP CONSTRAINT chk_equipos_tipo');
        DB::statement("ALTER TABLE equipos ADD CONSTRAINT chk_equipos_tipo CHECK (tipo IN ('audifonos','notebook','cargador'))");

        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_tipo_item');
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_tipo_item CHECK (tipo_item IN ('libro','audifonos','notebook','cargador'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE equipos DROP CONSTRAINT chk_equipos_tipo');
        DB::statement("ALTER TABLE equipos ADD CONSTRAINT chk_equipos_tipo CHECK (tipo IN ('audifonos','notebook'))");

        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_tipo_item');
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_tipo_item CHECK (tipo_item IN ('libro','audifonos','notebook'))");
    }
};
