<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La descripción física (300) de los juegos y kits trae el detalle de todo lo que viene
 * en la caja (Horizon la guarda en bib_longtext) y pasa de 255 caracteres: va a texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN descripcion_fisica TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN descripcion_fisica TYPE varchar(255) USING left(descripcion_fisica, 255)');
    }
};
