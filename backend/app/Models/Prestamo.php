<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    // Columna generada en la base (tipo_item sin los libros), solo para la FK a categorias_recurso.
    protected $hidden = ['categoria_recurso_clave'];

    protected function casts(): array
    {
        return [
            'fecha_prestamo' => 'datetime',
            'fecha_devolucion' => 'datetime',
            'fecha_devolucion_real' => 'datetime',
        ];
    }

    /**
     * Atrasados: sin devolver y con la fecha de devolución ya pasada (el que vence hoy
     * todavía no). Es la única definición de atraso: el estado dice solo si se devolvió.
     * estaVencido() del frontend usa la misma.
     */
    public function scopeVencidos(Builder $query): Builder
    {
        return $query->where('estado', 'activo')->whereDate('fecha_devolucion', '<', now()->toDateString());
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

    public function multas(): HasMany
    {
        return $this->hasMany(Multa::class);
    }

    public function prestadoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'prestado_por_staff_id')->withTrashed();
    }

    public function devueltoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'devuelto_por_staff_id')->withTrashed();
    }
}
