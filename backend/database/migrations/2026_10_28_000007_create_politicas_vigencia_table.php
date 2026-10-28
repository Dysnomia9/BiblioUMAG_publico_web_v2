<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuánto dura una cuenta al renovarla, por tipo de usuario. Los docentes y funcionarios
     * rotan menos que los estudiantes: renovarlos cada año es más trabajo que beneficio, así
     * que cada tipo puede vencer en una fecha fija del año, tras una cantidad de años o nunca.
     * Se parte con la regla de siempre para los tres (31 de marzo del año siguiente).
     */
    public function up(): void
    {
        Schema::create('politicas_vigencia', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20)->unique();
            // fecha_fija: el mes/día del año calendario siguiente; duracion: cuántos años desde la
            // renovación; sin_vencimiento: la cuenta no vence.
            $table->string('modo', 20)->default('fecha_fija');
            $table->unsignedTinyInteger('mes')->nullable();
            $table->unsignedTinyInteger('dia')->nullable();
            $table->unsignedTinyInteger('anios')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE politicas_vigencia ADD CONSTRAINT chk_politicas_vigencia_tipo CHECK (tipo IN ('estudiante','docente','funcionario'))");
        DB::statement("ALTER TABLE politicas_vigencia ADD CONSTRAINT chk_politicas_vigencia_modo CHECK (modo IN ('fecha_fija','duracion','sin_vencimiento'))");

        $ahora = now();
        foreach (['estudiante', 'docente', 'funcionario'] as $tipo) {
            DB::table('politicas_vigencia')->insert([
                'tipo' => $tipo, 'modo' => 'fecha_fija', 'mes' => 3, 'dia' => 31, 'anios' => null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('politicas_vigencia');
    }
};
