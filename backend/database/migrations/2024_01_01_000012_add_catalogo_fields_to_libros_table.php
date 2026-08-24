<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('autor')->nullable()->after('titulo');
            $table->string('categoria')->nullable()->after('autor');
            $table->boolean('disponible')->default(true)->after('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn(['autor', 'categoria', 'disponible']);
        });
    }
};
