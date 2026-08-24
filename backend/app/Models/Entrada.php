<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entrada extends Model
{
    use HasFactory;

    protected $table = 'entradas';

    protected $fillable = [
        'usuario_id',
        'registrado_por_staff_id',
        'rut_externo',
        'nombre_externo',
        'fecha_hora_entrada',
        'fecha_hora_salida',
        'via',
        'codigo_barras',
        'es_convenio',
        'es_visita',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora_entrada' => 'datetime',
            'fecha_hora_salida' => 'datetime',
            'es_convenio' => 'boolean',
            'es_visita' => 'boolean',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function registradoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'registrado_por_staff_id');
    }
}
