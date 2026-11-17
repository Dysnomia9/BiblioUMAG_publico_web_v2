<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Material prestado por otra biblioteca: su ficha y plazo existen antes de ingresar copias. */
class FichaInterbibliotecaria extends Model
{
    protected $table = 'fichas_interbibliotecarias';

    protected $fillable = ['libro_id', 'biblioteca', 'fecha_ingreso', 'fecha_limite', 'nota', 'registrado_por_staff_id'];

    protected function casts(): array
    {
        return ['fecha_ingreso' => 'date:Y-m-d', 'fecha_limite' => 'date:Y-m-d'];
    }

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class);
    }

    public function ingresos(): HasMany
    {
        return $this->hasMany(PrestamoInterbibliotecario::class, 'ficha_interbibliotecaria_id');
    }
}
