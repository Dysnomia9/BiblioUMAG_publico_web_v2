<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cómo llegó cada copia a la biblioteca (compra, donación o canje). Es de la
     * copia y no de la obra, igual que el precio: dos copias del mismo título
     * pueden venir una comprada y otra donada. El detalle (de quién, cuándo)
     * sigue yendo en la nota local 590 de la ficha.
     */
    public function up(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('forma_adquisicion', 20)->nullable()->after('precio');
        });

        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_forma_adquisicion CHECK (forma_adquisicion IS NULL OR forma_adquisicion IN ('compra','donacion','canje'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ejemplares DROP CONSTRAINT chk_ejemplares_forma_adquisicion');

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropColumn('forma_adquisicion');
        });
    }
};
