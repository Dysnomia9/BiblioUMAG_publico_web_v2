<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EjemplarEstadoHistorial extends Model
{
    protected $table = 'ejemplar_estado_historial';

    protected $fillable = [
        'ejemplar_id',
        'estado_anterior',
        'estado_nuevo',
        'estado_personalizado_anterior_id',
        'estado_personalizado_nuevo_id',
        'staff_id',
        'lote_id',
        'motivo',
    ];

    public function ejemplar(): BelongsTo
    {
        return $this->belongsTo(Ejemplar::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function estadoPersonalizadoAnterior(): BelongsTo
    {
        return $this->belongsTo(EstadoLibroPersonalizado::class, 'estado_personalizado_anterior_id');
    }

    public function estadoPersonalizadoNuevo(): BelongsTo
    {
        return $this->belongsTo(EstadoLibroPersonalizado::class, 'estado_personalizado_nuevo_id');
    }
}
