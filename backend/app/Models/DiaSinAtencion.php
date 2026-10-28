<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Feriado, vacaciones o cierre: de toda la biblioteca (sin ubicación) o de una ubicación. */
class DiaSinAtencion extends Model
{
    use HasFactory;

    protected $table = 'dias_sin_atencion';

    protected $fillable = ['fecha', 'ubicacion_id', 'motivo'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
        ];
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }
}
