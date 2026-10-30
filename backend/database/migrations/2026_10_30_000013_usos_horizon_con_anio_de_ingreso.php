<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * usos_sala_horizon pasa a usos_horizon: además de las logias (Uso de salas) guarda los puestos
 * de estudio, que en los reportes son ingresos a la biblioteca, no salas. Suma el año de
 * ingreso de quien lo pidió, que trae la carga masiva de estudiantes (Horizon no lo tiene).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('usos_sala_horizon', 'usos_horizon');
        DB::statement('ALTER INDEX usos_sala_horizon_pkey RENAME TO usos_horizon_pkey');
        DB::statement('ALTER INDEX usos_sala_horizon_fecha_index RENAME TO usos_horizon_fecha_index');
        DB::statement('ALTER SEQUENCE usos_sala_horizon_id_seq RENAME TO usos_horizon_id_seq');
        DB::statement('ALTER TABLE usos_horizon RENAME CONSTRAINT chk_usos_sala_horizon_servicio TO chk_usos_horizon_servicio');
        DB::statement('ALTER TABLE usos_horizon RENAME CONSTRAINT chk_usos_sala_horizon_horas TO chk_usos_horizon_horas');

        Schema::table('usos_horizon', function (Blueprint $table) {
            $table->unsignedSmallInteger('anio_ingreso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usos_horizon', function (Blueprint $table) {
            $table->dropColumn('anio_ingreso');
        });

        DB::statement('ALTER TABLE usos_horizon RENAME CONSTRAINT chk_usos_horizon_horas TO chk_usos_sala_horizon_horas');
        DB::statement('ALTER TABLE usos_horizon RENAME CONSTRAINT chk_usos_horizon_servicio TO chk_usos_sala_horizon_servicio');
        DB::statement('ALTER SEQUENCE usos_horizon_id_seq RENAME TO usos_sala_horizon_id_seq');
        DB::statement('ALTER INDEX usos_horizon_fecha_index RENAME TO usos_sala_horizon_fecha_index');
        DB::statement('ALTER INDEX usos_horizon_pkey RENAME TO usos_sala_horizon_pkey');
        Schema::rename('usos_horizon', 'usos_sala_horizon');
    }
};
