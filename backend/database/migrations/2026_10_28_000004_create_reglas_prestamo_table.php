<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reglas de préstamo por tipo de usuario, tipo de material y ubicación (null en
     * cualquiera de los tres = vale para todos). Al prestar se usa la más específica
     * que calce (App\Services\ReglaPrestamoService); si ninguna calza, sigue lo de
     * siempre: config/multas.php y un libro a la vez.
     *
     * El índice único trata los NULL como iguales (NULLS NOT DISTINCT, PostgreSQL 15+):
     * si no, dos reglas «para todos» pasarían el unique, y el ETL no podría hacer
     * upsert sobre estas tres columnas.
     */
    public function up(): void
    {
        Schema::create('reglas_prestamo', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_usuario', 20)->nullable();
            $table->foreignId('tipo_material_id')->nullable()->constrained('tipos_material')->restrictOnDelete();
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            // Null: la regla no propone fecha (por ejemplo, material que no se presta).
            $table->unsignedSmallInteger('dias_prestamo')->nullable();
            $table->unsignedSmallInteger('max_ejemplares')->nullable();
            $table->unsignedInteger('multa_por_dia')->nullable();
            $table->unsignedInteger('tope_multa')->nullable();
            $table->boolean('prestable')->default(true);
            $table->timestamps();

            $table->index('ubicacion_id');
        });

        DB::statement('CREATE UNIQUE INDEX reglas_prestamo_tipo_usuario_tipo_material_id_ubicacion_id_unique ON reglas_prestamo (tipo_usuario, tipo_material_id, ubicacion_id) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE reglas_prestamo ADD CONSTRAINT chk_reglas_prestamo_tipo_usuario CHECK (tipo_usuario IS NULL OR tipo_usuario IN ('estudiante','docente','funcionario'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_prestamo');
    }
};
