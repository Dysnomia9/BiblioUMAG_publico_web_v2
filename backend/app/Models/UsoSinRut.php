<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un libro usado en la biblioteca sin préstamo, registrado al pistolearlo en el mesón. */
class UsoSinRut extends Model
{
    protected $table = 'usos_sin_rut';

    protected $fillable = ['ejemplar_id', 'libro_titulo', 'codigo_barras', 'fecha_hora', 'registrado_por', 'registrado_por_staff_id'];

    protected function casts(): array
    {
        return ['fecha_hora' => 'datetime'];
    }

    public function ejemplar(): BelongsTo
    {
        return $this->belongsTo(Ejemplar::class);
    }
}
