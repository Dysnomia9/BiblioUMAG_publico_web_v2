<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de lo que hace cada persona en el sistema: quién prestó, devolvió,
     * cursó o anuló una multa, cambió el estado de un libro, creó o editó un
     * estudiante, etc. Cada fila guarda el nombre de quien actuó tal como era en ese
     * momento (actor_nombre), así que el registro sigue legible aunque la cuenta
     * cambie de nombre. Las filas no se editan ni se borran: no hay updated_at.
     */
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            // Cuando actúa el propio usuario desde el portal (marcar entrada, reservar).
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->string('actor_nombre');
            $table->string('modulo', 40);
            $table->string('accion', 60);
            $table->text('descripcion');
            $table->string('afectado')->nullable();
            $table->string('entidad_tipo', 60)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->jsonb('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['modulo', 'created_at']);
            $table->index(['staff_id', 'created_at']);
            $table->index(['entidad_tipo', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
