<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El «Último acceso» de Administración › Personal se calculaba con las sesiones abiertas
 * (personal_access_tokens.last_used_at), y esas se borran al cerrar sesión, al cambiar o
 * restablecer la contraseña y cada noche las vencidas (sanctum:prune-expired): quien entró y
 * salió volvía a figurar como «Nunca». Ahora queda en la cuenta (staff.ultimo_acceso_at), y
 * se llena con lo que ya se sabe: el último «Inició sesión» de la bitácora y el último uso de
 * las sesiones que siguen abiertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->timestamp('ultimo_acceso_at')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE staff s SET ultimo_acceso_at = GREATEST(ingresos.ultimo, sesiones.ultimo)
            FROM staff c
            LEFT JOIN (
                SELECT entidad_id AS id, max(created_at) AS ultimo FROM bitacora
                WHERE accion = 'sesion.ingresar' AND entidad_tipo = 'Staff' GROUP BY entidad_id
            ) ingresos ON ingresos.id = c.id
            LEFT JOIN (
                SELECT tokenable_id AS id, max(last_used_at) AS ultimo FROM personal_access_tokens
                WHERE tokenable_type = 'App\Models\Staff' GROUP BY tokenable_id
            ) sesiones ON sesiones.id = c.id
            WHERE c.id = s.id AND (ingresos.ultimo IS NOT NULL OR sesiones.ultimo IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('ultimo_acceso_at');
        });
    }
};
