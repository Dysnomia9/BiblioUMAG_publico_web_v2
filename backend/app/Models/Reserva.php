<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Reserva extends Model
{
    use HasFactory;

    protected $table = 'reservas';

    protected $fillable = [
        'sala_id',
        'usuario_id',
        'rut_usuario',
        'cantidad_personas',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'estado',
        'prestado_por',
        'prestado_por_staff_id',
        'devuelto_por',
        'devuelto_por_staff_id',
        'hora_prestamo_real',
        'hora_devolucion_real',
        'via',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'hora_prestamo_real' => 'datetime',
            'hora_devolucion_real' => 'datetime',
        ];
    }

    public function sala()
    {
        return $this->belongsTo(Sala::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function participantes()
    {
        return $this->belongsToMany(Usuario::class, 'reserva_participantes')->withTimestamps();
    }

    public function prestadoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'prestado_por_staff_id');
    }

    public function devueltoPorStaff()
    {
        return $this->belongsTo(Staff::class, 'devuelto_por_staff_id');
    }

    /**
     * Plazo máximo para que el grupo se presente a confirmar la reserva: 15 minutos
     * desde que comienza el tramo reservado — salvo que la reserva se haya creado
     * DESPUÉS de que el tramo ya empezó (ej. "Reservar ahora" a las 15:40 dentro de un
     * tramo 15:00-17:00 no debería pasar, pero por seguridad se toma el máximo entre
     * ambos), en cuyo caso el plazo corre desde el momento de la reserva, no desde el
     * inicio nominal del tramo (que ya pasó).
     */
    public function plazoConfirmacion(): Carbon
    {
        $inicioTramo = Carbon::parse($this->fecha->toDateString().' '.$this->hora_inicio);
        $base = $this->created_at->greaterThan($inicioTramo) ? $this->created_at : $inicioTramo;

        return $base->copy()->addMinutes(config('salas.plazo_confirmacion', 15));
    }

    public function estaVencidaSinConfirmar(): bool
    {
        return $this->estado === 'activa'
            && ! $this->hora_prestamo_real
            && now()->greaterThan($this->plazoConfirmacion());
    }

    /** Minutos que dura el tramo reservado (hora_fin - hora_inicio). */
    public function duracionMinutos(): int
    {
        return Carbon::parse($this->hora_inicio)->diffInMinutes(Carbon::parse($this->hora_fin));
    }
}
