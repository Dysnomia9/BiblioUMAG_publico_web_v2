<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoMaterial extends Model
{
    use HasFactory;

    protected $table = 'tipos_material';

    /** plantilla: qué ficha bibliográfica usa (ver App\Support\FichaBibliografica::PLANTILLAS). */
    protected $fillable = ['nombre', 'plantilla'];

    public function libros(): HasMany
    {
        return $this->hasMany(Libro::class);
    }
}
