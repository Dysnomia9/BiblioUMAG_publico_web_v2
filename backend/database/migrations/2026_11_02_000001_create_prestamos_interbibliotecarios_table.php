<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Préstamo interbibliotecario: material que otra biblioteca (la propietaria) le presta
     * a la nuestra por un tiempo. Cada uno es una copia en `ejemplares` que llegó el
     * fecha_ingreso y tiene que estar de vuelta en su biblioteca el fecha_limite; a los
     * usuarios se les presta hasta un día hábil antes (PrestamoInterbibliotecario::fechaTope()).
     *
     * Al devolverla a su biblioteca (devuelto_el) la copia pasa al estado nuevo
     * «devuelto_propietaria»: sale del catálogo y del portal, pero sigue en la base con
     * sus préstamos, que cuentan en los reportes.
     */
    public function up(): void
    {
        Schema::create('prestamos_interbibliotecarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ejemplar_id')->unique()->constrained('ejemplares')->restrictOnDelete();
            $table->string('biblioteca');
            $table->date('fecha_ingreso');
            $table->date('fecha_limite');
            $table->date('devuelto_el')->nullable();
            $table->text('nota')->nullable();
            $table->foreignId('registrado_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('devuelto_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index('fecha_ingreso');
            $table->index('devuelto_el');
        });

        DB::statement('ALTER TABLE prestamos_interbibliotecarios ADD CONSTRAINT chk_prestamos_interbibliotecarios_fechas CHECK (fecha_limite >= fecha_ingreso AND (devuelto_el IS NULL OR devuelto_el >= fecha_ingreso))');

        DB::statement('ALTER TABLE ejemplares DROP CONSTRAINT IF EXISTS chk_ejemplares_estado_proceso');
        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_estado_proceso CHECK (estado_proceso IN ('inventario','procesos_tecnicos','por_colocar','en_estante','estanteria_auxiliar','de_baja','coleccion_movil','personalizado','devuelto_propietaria'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos_interbibliotecarios');

        // Sin el estado nuevo, lo que ya volvió a su biblioteca queda como dado de baja.
        DB::statement("UPDATE ejemplares SET estado_proceso = 'de_baja' WHERE estado_proceso = 'devuelto_propietaria'");
        DB::statement('ALTER TABLE ejemplares DROP CONSTRAINT IF EXISTS chk_ejemplares_estado_proceso');
        DB::statement("ALTER TABLE ejemplares ADD CONSTRAINT chk_ejemplares_estado_proceso CHECK (estado_proceso IN ('inventario','procesos_tecnicos','por_colocar','en_estante','estanteria_auxiliar','de_baja','coleccion_movil','personalizado'))");
    }
};
