<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Toda lectura/escritura de multas pasa por App\Services\MultaService — cuando
 * llegue el registro institucional de multas, ahí es donde se adapta (o se
 * importan sus filas con origen = 'importada' y su id en referencia_externa).
 */
class Multa extends Model
{
    use HasFactory;

    protected $table = 'multas';

    public const TIPOS = [
        'atraso' => 'Atraso en la devolución',
        'deterioro' => 'Deterioro del material',
        'perdida' => 'Pérdida del material',
        'otro' => 'Otro motivo',
    ];

    /** Tipos que el personal puede cursar a mano; 'atraso' solo lo genera la devolución. */
    public const TIPOS_MANUALES = ['deterioro', 'perdida', 'otro'];

    protected $fillable = [
        'usuario_id',
        'prestamo_id',
        'tipo',
        'monto',
        'estado',
        'detalle',
        'dias_atraso',
        'origen',
        'referencia_externa',
        'registrada_por_staff_id',
        'pagada_en',
        'pagada_por_staff_id',
        'anulada_en',
        'anulada_por_staff_id',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'integer',
            'dias_atraso' => 'integer',
            'pagada_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', 'pendiente');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'registrada_por_staff_id')->withTrashed();
    }

    public function pagadaPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'pagada_por_staff_id')->withTrashed();
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'anulada_por_staff_id')->withTrashed();
    }
}
