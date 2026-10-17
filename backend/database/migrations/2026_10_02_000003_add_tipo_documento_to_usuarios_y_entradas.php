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
     * El índice sobre el identificador sin puntos, guiones ni espacios es el que usan
     * las búsquedas (App\Support\Documento::normalizar) y el que impide que la misma
     * persona quede dos veces escrita de distinta forma («12.345.678-5» y «123456785»).
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
        $duplicados = DB::table('usuarios')
            ->selectRaw("upper(regexp_replace(rut, '[^0-9A-Za-z]', '', 'g')) as identificador")
            ->groupByRaw("upper(regexp_replace(rut, '[^0-9A-Za-z]', '', 'g'))")
            ->havingRaw('count(*) > 1')
            ->exists();

        DB::statement(sprintf(
            "CREATE %s INDEX usuarios_identificador_normalizado ON usuarios ((upper(regexp_replace(rut, '[^0-9A-Za-z]', '', 'g'))))",
            $duplicados ? '' : 'UNIQUE',
        ));

        Schema::table('entradas', function (Blueprint $table) {
            $table->string('tipo_documento_externo', 20)->nullable()->after('rut_externo');
            $table->string('pais_externo', 100)->nullable()->after('tipo_documento_externo');
        });

        DB::statement("ALTER TABLE entradas ADD CONSTRAINT chk_entradas_tipo_documento_externo CHECK (tipo_documento_externo IS NULL OR tipo_documento_externo IN ('rut','pasaporte','dni','otro'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entradas DROP CONSTRAINT IF EXISTS chk_entradas_tipo_documento_externo');
        Schema::table('entradas', function (Blueprint $table) {
            $table->dropColumn(['tipo_documento_externo', 'pais_externo']);
        });

        DB::statement('DROP INDEX IF EXISTS usuarios_identificador_normalizado');
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS chk_usuarios_tipo_documento');
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['tipo_documento', 'pais']);
        });
    }
};
