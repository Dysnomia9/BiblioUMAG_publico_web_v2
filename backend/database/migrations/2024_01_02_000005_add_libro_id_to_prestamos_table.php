<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            // Nullable: los equipos (audífonos, notebooks) no están en el catálogo de
            // libros, se identifican por código de inventario en texto libre.
            $table->foreignId('libro_id')->nullable()->after('usuario_id')->constrained('libros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('libro_id');
        });
    }
};
