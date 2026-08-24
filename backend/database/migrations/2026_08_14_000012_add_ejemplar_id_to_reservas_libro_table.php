<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas_libro', function (Blueprint $table) {
            // libro_id se mantiene (sigue apuntando a libros.id, que ahora es la obra
            // bibliográfica deseada). ejemplar_id es la copia física concreta asignada
            // — null mientras estado='en_cola' (todavía no se le asignó copia).
            $table->foreignId('ejemplar_id')->nullable()->after('libro_id')
                ->constrained('ejemplares')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas_libro', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ejemplar_id');
        });
    }
};
