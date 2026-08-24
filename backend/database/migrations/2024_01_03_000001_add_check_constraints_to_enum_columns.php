<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Postgres no tiene ->check() nativo en el Schema Builder de Laravel — se usa
     * DB::statement(). Los valores permitidos se toman de las reglas 'in:' ya
     * existentes en cada controller, incluyendo valores que hoy solo escribe el
     * seeder (prestamos.estado='atrasado', reservas_libro.estado='retirado') para
     * que 'mockup:datos' no rompa. usuarios.sexo queda fuera a propósito: no es un
     * enum simulado, no tiene ninguna regla 'in:' en ningún controller.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT chk_usuarios_tipo CHECK (tipo IN ('estudiante','docente','funcionario'))");

        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_estado CHECK (estado IN ('activo','devuelto','atrasado'))");
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_tipo_item CHECK (tipo_item IN ('libro','audifonos','notebook'))");
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_multa_estado CHECK (multa_estado IS NULL OR multa_estado IN ('pendiente','pagada'))");

        DB::statement("ALTER TABLE libros ADD CONSTRAINT chk_libros_tipo_material CHECK (tipo_material IS NULL OR tipo_material IN ('libro','revista','tesis','dvd','otro'))");
        DB::statement("ALTER TABLE libros ADD CONSTRAINT chk_libros_estado_proceso CHECK (estado_proceso IN ('inventario','procesos_tecnicos','por_colocar','en_estante','estanteria_auxiliar','de_baja'))");

        DB::statement("ALTER TABLE reservas_libro ADD CONSTRAINT chk_reservas_libro_estado CHECK (estado IN ('pendiente','cancelado','retirado'))");

        DB::statement("ALTER TABLE equipos ADD CONSTRAINT chk_equipos_tipo CHECK (tipo IN ('audifonos','notebook'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT chk_usuarios_tipo');
        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_estado');
        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_tipo_item');
        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_multa_estado');
        DB::statement('ALTER TABLE libros DROP CONSTRAINT chk_libros_tipo_material');
        DB::statement('ALTER TABLE libros DROP CONSTRAINT chk_libros_estado_proceso');
        DB::statement('ALTER TABLE reservas_libro DROP CONSTRAINT chk_reservas_libro_estado');
        DB::statement('ALTER TABLE equipos DROP CONSTRAINT chk_equipos_tipo');
    }
};
