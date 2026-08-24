<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sala extends Model
{
    use HasFactory;

    protected $table = 'salas';

    protected $fillable = [
        'nombre',
        'capacidad',
        'tipo',
        'codigo_barras',
    ];

    public function reservas()
    {
        return $this->hasMany(Reserva::class);
    }
}
