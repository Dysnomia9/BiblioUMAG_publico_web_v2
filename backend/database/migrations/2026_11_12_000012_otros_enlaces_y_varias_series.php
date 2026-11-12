<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos repetibles de Horizon que se perdían al pasar solo el primero:
 * - otros_enlaces: los 856 después del primero (url_acceso), uno por línea, «URL texto»
 *   (p. ej. un enlace por tomo: «… Vea aquí versión digital, v.2»).
 * - serie pasa a texto: un título puede tener varias series (490), una por línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->text('otros_enlaces')->nullable()->after('url_acceso');
        });
        DB::statement('ALTER TABLE libros ALTER COLUMN serie TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN serie TYPE varchar(255) USING left(serie, 255)');
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('otros_enlaces');
        });
    }
};
