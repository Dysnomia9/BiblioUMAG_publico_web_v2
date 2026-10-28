<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correos adicionales de un usuario (el institucional y el personal, por ejemplo). El
     * principal sigue en usuarios.email, que es el que usan las notificaciones; los de acá
     * sirven además para reconocer a la persona al entrar con Google o el directorio y se
     * suman solos cuando una carga masiva trae un correo distinto al que ya tenía.
     * Un correo no puede estar en dos personas, sea el principal de una o adicional de otra
     * (eso lo valida la aplicación; acá la base solo impide repetirlo entre adicionales).
     */
    public function up(): void
    {
        Schema::create('usuario_correos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('email', 255);
            $table->timestamps();

            $table->index('usuario_id');
        });

        DB::statement('CREATE UNIQUE INDEX usuario_correos_email_unique ON usuario_correos (lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_correos');
    }
};
