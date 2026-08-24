<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Precarga de conveniencia — estos valores son 100% administrables después desde
 * Administración (crear/desactivar), esto solo evita que el staff tenga que crearlos
 * a mano el primer día. Mismo patrón que el seed de `carreras` en su propia migración.
 */
return new class extends Migration
{
    private array $estados = [
        ['nombre' => 'Obsoleto', 'descripcion' => 'Contenido desactualizado, ya no vigente para consulta'],
        ['nombre' => 'Pérdida', 'descripcion' => 'Ejemplar extraviado, no se encuentra en la biblioteca'],
        ['nombre' => 'Hongos', 'descripcion' => 'Con daño por hongos/humedad, requiere tratamiento antes de volver a circular'],
        ['nombre' => 'Descarte', 'descripcion' => 'Marcado para dar de baja definitivamente del inventario'],
        ['nombre' => 'Dañado', 'descripcion' => 'Daño físico (páginas rotas, encuadernación, etc.)'],
    ];

    public function up(): void
    {
        $ahora = now();

        DB::table('estados_libro_personalizados')->insert(
            collect($this->estados)->map(fn ($estado) => [
                'nombre' => $estado['nombre'],
                'descripcion' => $estado['descripcion'],
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all()
        );

        DB::table('carreras')->insert([
            'nombre' => 'General / Otros',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        DB::table('estados_libro_personalizados')->whereIn('nombre', collect($this->estados)->pluck('nombre'))->delete();
        DB::table('carreras')->where('nombre', 'General / Otros')->delete();
    }
};
