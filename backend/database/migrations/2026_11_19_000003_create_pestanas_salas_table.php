<?php

use App\Models\PestanaSala;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las pestañas del mesón de salas (Piso 1, Piso 2, Hospital, Administración…) dejan de estar
 * fijas en el código: se crean, renombran, ordenan y eliminan desde la misma pantalla, y cada
 * sala va en una (o en ninguna: entonces sale en «Otras»). Una pestaña «apartada» va al final,
 * separada de las demás, como la de Administración. Al eliminar una pestaña sus salas quedan
 * sin pestaña; las salas y su historial no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pestanas_salas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 40)->unique();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('apartada')->default(false);
            $table->timestamps();
        });

        Schema::table('salas', function (Blueprint $table) {
            $table->foreignId('pestana_id')->nullable()->after('tipo')->constrained('pestanas_salas')->nullOnDelete();
        });

        PestanaSala::crearIniciales();
    }

    public function down(): void
    {
        Schema::table('salas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pestana_id');
        });
        Schema::dropIfExists('pestanas_salas');
    }
};
