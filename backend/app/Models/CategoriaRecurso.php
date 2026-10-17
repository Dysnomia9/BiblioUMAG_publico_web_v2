<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de recurso de apoyo (recurso no bibliográfico que se presta): audífonos,
 * notebook, cargador, bicicleta… Se administra en Administración → Catálogos. Su
 * `clave` es lo que guardan equipos.tipo y prestamos.tipo_item, y no cambia nunca.
 */
class CategoriaRecurso extends Model
{
    use HasFactory;

    protected $table = 'categorias_recurso';

    /** Íconos que el frontend sabe dibujar (frontend/src/utils/recursos.ts). */
    public const ICONOS = ['audifonos', 'notebook', 'cargador', 'bicicleta', 'tablet', 'calculadora', 'camara', 'llave', 'paraguas', 'pelota', 'caja'];

    public const COLORES = ['violet', 'sky', 'teal', 'emerald', 'amber', 'rose', 'indigo', 'orange', 'slate'];

    protected $fillable = [
        'clave',
        'nombre',
        'nombre_plural',
        'icono',
        'color',
        'limite_por_persona',
        'indicaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'limite_por_persona' => 'integer',
        ];
    }

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'tipo', 'clave');
    }
}
