<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstadoLibroPersonalizado extends Model
{
    use HasFactory;

    protected $table = 'estados_libro_personalizados';

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function ejemplares(): HasMany
    {
        return $this->hasMany(Ejemplar::class, 'estado_personalizado_id');
    }
}
