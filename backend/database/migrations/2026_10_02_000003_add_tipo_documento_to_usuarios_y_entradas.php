<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estudiantes y visitantes extranjeros se identifican con pasaporte o con el
     * documento de su país (DNI argentino, cédula, etc.) en vez de RUT. La columna
     * `rut` sigue siendo el identificador único de la persona —cambiarle el nombre
     * tocaría todo el sistema—, y `tipo_documento` dice qué es: un RUT se guarda con
     * puntos y guion, un pasaporte o DNI en mayúsculas y sin espacios.
     *
     * `rut_normalizado` es el identificador sin puntos, guiones ni espacios, calculado
     * por MySQL (columna generada): es el que usan las búsquedas
     * (App\Support\Documento::normalizar) y su índice único impide que la misma persona
     * quede dos veces escrita de distinta forma («12.345.678-5» y «123456785»).
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('tipo_documento', 20)->default('rut')->after('rut');
            $table->string('pais', 100)->nullable()->after('tipo_documento');
        });

        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT chk_usuarios_tipo_documento CHECK (tipo_documento IN ('rut','pasaporte','dni','otro'))");

        // Una base con la misma persona escrita de dos formas no admite el índice
        // único: queda como índice normal (las búsquedas igual lo usan) y los
        // duplicados se corrigen a mano antes de volver a correr esta parte.
        DB::statement("ALTER TABLE usuarios ADD COLUMN rut_normalizado VARCHAR(255) GENERATED ALWAYS AS (UPPER(REGEXP_REPLACE(rut, '[^0-9A-Za-z]', ''))) STORED AFTER rut");

        $duplicados = DB::table('usuarios')
            ->select('rut_normalizado')
            ->groupBy('rut_normalizado')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        Schema::table('usuarios', function (Blueprint $table) use ($duplicados) {
            $duplicados
                ? $table->index('rut_normalizado', 'usuarios_identificador_normalizado')
                : $table->unique('rut_normalizado', 'usuarios_identificador_normalizado');
        });

        Schema::table('entradas', function (Blueprint $table) {
            $table->string('tipo_documento_externo', 20)->nullable()->after('rut_externo');
            $table->string('pais_externo', 100)->nullable()->after('tipo_documento_externo');
        });

        DB::statement("ALTER TABLE entradas ADD CONSTRAINT chk_entradas_tipo_documento_externo CHECK (tipo_documento_externo IS NULL OR tipo_documento_externo IN ('rut','pasaporte','dni','otro'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entradas DROP CONSTRAINT chk_entradas_tipo_documento_externo');
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropColumn(['tipo_documento_externo', 'pais_externo']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropIndex('usuarios_identificador_normalizado');
            $table->dropColumn('rut_normalizado');
        });
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT chk_usuarios_tipo_documento');
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['tipo_documento', 'pais']);
        });
    }
};
