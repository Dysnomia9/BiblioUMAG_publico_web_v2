<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_inventario')->unique();
            $table->string('tipo'); // audifonos|notebook
            $table->boolean('disponible')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};
