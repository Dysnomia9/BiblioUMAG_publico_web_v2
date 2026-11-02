<?php

namespace App\Models;

use App\Support\HorarioBiblioteca;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una copia que otra biblioteca (la propietaria) le prestó a la nuestra: llegó el
 * fecha_ingreso y tiene que estar de vuelta en su biblioteca el fecha_limite. A los
 * usuarios se les presta hasta un día hábil antes (fechaTope()), para alcanzar a
 * mandarla. devuelto_el es cuándo se le devolvió; la copia queda en «devuelto_propietaria».
 */
class PrestamoInterbibliotecario extends Model
{
    protected $table = 'prestamos_interbibliotecarios';

    protected $fillable = [
        'ejemplar_id',
        'biblioteca',
        'fecha_ingreso',
        'fecha_limite',
        'devuelto_el',
        'nota',
        'registrado_por_staff_id',
        'devuelto_por_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date:Y-m-d',
            'fecha_limite' => 'date:Y-m-d',
            'devuelto_el' => 'date:Y-m-d',
        ];
    }

    public function ejemplar(): BelongsTo
    {
        return $this->belongsTo(Ejemplar::class);
    }

    /** Los préstamos a usuarios de su copia: siguen contando aunque ya haya vuelto a su biblioteca. */
    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'ejemplar_id', 'ejemplar_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'registrado_por_staff_id')->withTrashed();
    }

    public function devueltoPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'devuelto_por_staff_id')->withTrashed();
    }

    /** Las que siguen en la biblioteca (todavía no se devuelven a su biblioteca). */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('devuelto_el');
    }

    /** El último día en que un usuario puede devolverla: un día hábil antes de la fecha límite. */
    public function fechaTope(): Carbon
    {
        return HorarioBiblioteca::diaHabilAnterior($this->fecha_limite, $this->ejemplar?->ubicacion_id);
    }

    /**
     * Por qué no se le puede prestar a un usuario (o apartar en una reserva), o null si
     * se puede. Con $devolucion, también revisa que la fecha de devolución no pase el tope.
     */
    public function motivoRechazo(?Carbon $devolucion = null): ?string
    {
        if ($this->devuelto_el) {
            return "Este material ya se devolvió a {$this->biblioteca} el {$this->devuelto_el->format('d/m/Y')}.";
        }

        $tope = $this->fechaTope();
        $limite = $this->fecha_limite->format('d/m/Y');

        if ($tope->lt(today())) {
            return "Es material de préstamo interbibliotecario de {$this->biblioteca}: tiene que volver a su biblioteca el {$limite} y a los usuarios se prestaba hasta el {$tope->format('d/m/Y')}. Ya no se presta.";
        }

        if ($devolucion && $devolucion->copy()->startOfDay()->gt($tope)) {
            return "Es material de préstamo interbibliotecario de {$this->biblioteca}: se devuelve a más tardar el {$tope->format('d/m/Y')}, un día hábil antes de que tenga que volver a su biblioteca ({$limite}).";
        }

        return null;
    }

    /** Lo que ve el mesón al prestarla. */
    public function resumen(): array
    {
        return [
            'id' => $this->id,
            'biblioteca' => $this->biblioteca,
            'fecha_limite' => $this->fecha_limite->toDateString(),
            'fecha_tope' => $this->fechaTope()->toDateString(),
        ];
    }
}
