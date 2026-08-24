<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservaLibro extends Model
{
    use HasFactory;

    protected $table = 'reservas_libro';

    protected $fillable = [
        'usuario_id',
        'libro_id',
        'ejemplar_id',
        'fecha_reserva',
        'fecha_retiro',
        'estado',
        'registrado_por_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_reserva' => 'date',
            'fecha_retiro' => 'date',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function libro()
    {
        return $this->belongsTo(Libro::class);
    }

    public function ejemplar()
    {
        return $this->belongsTo(Ejemplar::class);
    }

    public function registradoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'registrado_por_staff_id');
    }
}
