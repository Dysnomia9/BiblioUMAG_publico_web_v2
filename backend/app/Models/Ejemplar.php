<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\Contador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ejemplar extends Model
{
    use HasFactory;

    protected $table = 'ejemplares';

    /** Cómo llegó la copia a la biblioteca (columna forma_adquisicion). */
    public const FORMAS_ADQUISICION = ['compra', 'donacion', 'canje'];

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
        'forma_adquisicion',
        'fecha_inventario',
    ];

    /**
     * El código de lomo (8 dígitos, desde 10000001) se asigna al crear la copia, con el
     * contador `codigo_lomo` (ver migración 2026_10_03_000001).
     */
    protected static function booted(): void
    {
        static::creating(function (Ejemplar $ejemplar) {
            $ejemplar->codigo_lomo ??= (string) Contador::siguiente('codigo_lomo', 10000001);
        });
    }

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

    /**
     * Busca la copia por cualquiera de sus dos códigos de barras: el de 14 dígitos o el
     * de 8 de la etiqueta de lomo (codigo_lomo, lo asigna la base al crear la copia).
     */
    public function scopePorCodigo(Builder $query, string $codigo): Builder
    {
        $codigo = trim($codigo);

        return $query->where(fn ($q) => $q->where('codigo_barras', $codigo)->orWhere('codigo_lomo', $codigo));
    }

    /** "Título (Copia N)" para copias 2+, o el título tal cual para la copia 1. */
    public function tituloConCopia(): string
    {
        $titulo = $this->libro->titulo;

        return $this->numero_copia > 1 ? "{$titulo} (Copia {$this->numero_copia})" : $titulo;
    }
}
