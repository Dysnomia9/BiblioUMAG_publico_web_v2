<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Carrera extends Model
{
    use HasFactory;

    protected $table = 'carreras';

    protected $fillable = ['nombre'];

    public function libros(): BelongsToMany
    {
        return $this->belongsToMany(Libro::class, 'libro_carrera');
    }
}
