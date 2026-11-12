<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El título alternativo (246) es repetible: una obra puede tener varios. Se guardan
 * uno por línea, como las materias personales (600), así que la columna pasa a texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN titulo_alternativo TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN titulo_alternativo TYPE varchar(255) USING left(titulo_alternativo, 255)');
    }
};
