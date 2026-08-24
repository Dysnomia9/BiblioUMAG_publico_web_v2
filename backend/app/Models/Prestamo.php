<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Prestamo extends Model
{
    use HasFactory;

    protected $table = 'prestamos';

    protected $fillable = [
        'usuario_id',
        'ejemplar_id',
        'equipo_id',
        'libro_titulo',
        'tipo_item',
        'codigo_barras',
        'fecha_prestamo',
        'fecha_devolucion',
        'fecha_devolucion_real',
        'estado',
        'prestado_por',
        'prestado_por_staff_id',
        'devuelto_por',
        'devuelto_por_staff_id',
        'multa_monto',
        'multa_estado',
        'multa_pagada_en',
        'multa_pagada_por',
        'multa_pagada_por_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_prestamo' => 'datetime',
            'fecha_devolucion' => 'datetime',
            'fecha_devolucion_real' => 'datetime',
            'multa_pagada_en' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function ejemplar()
    {
        return $this->belongsTo(Ejemplar::class);
    }

    public function equipo()
    {
        return $this->belongsTo(Equipo::class);
    }

    public function prestadoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'prestado_por_staff_id');
    }

    public function devueltoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'devuelto_por_staff_id');
    }

    public function multaPagadaPorStaff()
    {
        return $this->belongsTo(Staff::class, 'multa_pagada_por_staff_id');
    }
}
