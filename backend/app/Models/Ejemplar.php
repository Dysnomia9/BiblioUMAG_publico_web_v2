<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ejemplar extends Model
{
    use HasFactory;

    protected $table = 'ejemplares';

    protected $fillable = [
        'libro_id',
        'numero_copia',
        'codigo_barras',
        'disponible',
        'estado_proceso',
        'estado_personalizado_id',
        'ubicacion_id',
        'volumen',
        'precio',
        'fecha_inventario',
    ];

    protected function casts(): array
    {
        return [
            'disponible' => 'boolean',
            'precio' => 'decimal:2',
            'fecha_inventario' => 'date',
        ];
    }

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class);
    }

    public function estadoPersonalizado(): BelongsTo
    {
        return $this->belongsTo(EstadoLibroPersonalizado::class, 'estado_personalizado_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class);
    }

    public function reservasLibro(): HasMany
    {
        return $this->hasMany(ReservaLibro::class);
    }

    public function historialEstado(): HasMany
    {
        return $this->hasMany(EjemplarEstadoHistorial::class);
    }

    /** "Título (Copia N)" para copias 2+, o el título tal cual para la copia 1. */
    public function tituloConCopia(): string
    {
        $titulo = $this->libro->titulo;

        return $this->numero_copia > 1 ? "{$titulo} (Copia {$this->numero_copia})" : $titulo;
    }
}
