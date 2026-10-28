<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Equipo extends Model
{
    use HasFactory;

    protected $table = 'equipos';

    protected $fillable = [
        'codigo_inventario',
        'codigo_barras',
        'tipo',
        'disponible',
        'activo',
        'motivo_baja',
        'dado_de_baja_el',
    ];

    protected function casts(): array
    {
        return [
            'disponible' => 'boolean',
            'activo' => 'boolean',
            'dado_de_baja_el' => 'datetime',
        ];
    }

    public function prestamos()
    {
        return $this->hasMany(Prestamo::class);
    }

    /** `tipo` es la clave de su categoría de recurso de apoyo (bicicleta, notebook…). */
    public function categoria()
    {
        return $this->belongsTo(CategoriaRecurso::class, 'tipo', 'clave');
    }
}
