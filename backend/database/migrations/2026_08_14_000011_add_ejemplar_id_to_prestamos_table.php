<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            // Reemplaza a libro_id (ver migración drop_columnas_legacy_de_libros_y_prestamos
            // más adelante en esta misma tanda): un préstamo de libro siempre corresponde a
            // una copia física concreta, no solo a la obra.
            $table->foreignId('ejemplar_id')->nullable()->after('libro_id')
                ->constrained('ejemplares')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ejemplar_id');
        });
    }
};
