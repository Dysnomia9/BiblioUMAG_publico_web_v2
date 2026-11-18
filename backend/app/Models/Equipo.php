<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipo extends Model
{
    use HasFactory;
    use SoftDeletes;

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

    /** Acepta el código guardado y su versión corta de Horizon, conservando los ceros. */
    public function scopePorCodigo(Builder $query, string $codigo): Builder
    {
        return $query->whereIn('codigo_barras', Ejemplar::variantesCodigo($codigo));
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
