<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Equipo extends Model
{
    use HasFactory;

    protected $table = 'equipos';

    protected $fillable = [
        'codigo_inventario',
        'codigo_barras',
        'tipo',
        'disponible',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'disponible' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function prestamos()
    {
        return $this->hasMany(Prestamo::class);
    }
}
