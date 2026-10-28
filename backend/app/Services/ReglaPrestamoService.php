<?php

namespace App\Services;

use App\Models\Ejemplar;
use App\Models\Prestamo;
use App\Models\ReglaPrestamo;
use App\Models\Usuario;
use App\Support\HorarioBiblioteca;
use Illuminate\Support\Carbon;

/**
 * Qué regla de préstamo (tabla reglas_prestamo) se aplica a una persona y una copia.
 *
 * Calzan las reglas cuyo tipo de usuario, tipo de material y ubicación son iguales a
 * los del préstamo o null (comodín). Gana la más específica: la que fija más de los
 * tres criterios; si empatan, desempata el criterio más importante, en este orden:
 * tipo de material, ubicación, tipo de usuario (lo que es la copia y dónde está pesa
 * más que quién la pide: una obra de referencia no se presta, la pida quien la pida).
 * Ejemplos, para un estudiante que pide una revista de la Hemeroteca:
 * - «revista: no se presta» gana a «estudiantes: 7 días» (empate a uno; manda el material);
 * - «revista + Hemeroteca» gana a «estudiante + revista» (empate a dos; la ubicación
 *   pesa más que el tipo de usuario);
 * - «estudiante + revista» gana a «revista» sola (fija más criterios).
 *
 * Sin regla que calce, o en los valores que la regla deja en null, sigue lo de antes
 * de las reglas: un libro a la vez, la tarifa y el tope de config/multas.php y la
 * fecha de devolución que elija quien atiende.
 */
class ReglaPrestamoService
{
    /** Lo de antes de las reglas: un libro a la vez por persona. */
    public const MAX_EJEMPLARES_SIN_REGLA = 1;

    public function reglaPara(?string $tipoUsuario, ?int $tipoMaterialId, ?int $ubicacionId): ?ReglaPrestamo
    {
        $comodin = fn ($q, string $columna, $valor) => $q->where(fn ($w) => $w->whereNull($columna)->when($valor !== null, fn ($w) => $w->orWhere($columna, $valor)));

        return ReglaPrestamo::query()
            ->tap(fn ($q) => $comodin($q, 'tipo_usuario', $tipoUsuario))
            ->tap(fn ($q) => $comodin($q, 'tipo_material_id', $tipoMaterialId))
            ->tap(fn ($q) => $comodin($q, 'ubicacion_id', $ubicacionId))
            ->orderByRaw('(CASE WHEN tipo_usuario IS NULL THEN 0 ELSE 1 END) + (CASE WHEN tipo_material_id IS NULL THEN 0 ELSE 1 END) + (CASE WHEN ubicacion_id IS NULL THEN 0 ELSE 1 END) DESC')
            ->orderByRaw('(CASE WHEN tipo_material_id IS NULL THEN 0 ELSE 4 END) + (CASE WHEN ubicacion_id IS NULL THEN 0 ELSE 2 END) + (CASE WHEN tipo_usuario IS NULL THEN 0 ELSE 1 END) DESC')
            ->first();
    }

    public function reglaParaPrestar(Usuario $usuario, Ejemplar $ejemplar): ?ReglaPrestamo
    {
        $ejemplar->loadMissing('libro');

        return $this->reglaPara($usuario->tipo, $ejemplar->libro?->tipo_material_id, $ejemplar->ubicacion_id);
    }

    /** La regla de un préstamo de libro ya hecho (para calcular su multa al devolver). */
    public function reglaDelPrestamo(Prestamo $prestamo): ?ReglaPrestamo
    {
        $prestamo->loadMissing(['usuario', 'ejemplar.libro']);

        if (! $prestamo->ejemplar || ! $prestamo->usuario) {
            return null;
        }

        return $this->reglaParaPrestar($prestamo->usuario, $prestamo->ejemplar);
    }

    /** Cuántos libros puede tener la persona a la vez con esta regla. */
    public function maxEjemplares(?ReglaPrestamo $regla): int
    {
        return $regla?->max_ejemplares ?? self::MAX_EJEMPLARES_SIN_REGLA;
    }

    /** @return array{monto_dia: int, tope: int|null} */
    public function tarifaMulta(?ReglaPrestamo $regla): array
    {
        $tope = $regla?->tope_multa ?? config('multas.tope_maximo');

        return [
            'monto_dia' => (int) ($regla?->multa_por_dia ?? config('multas.monto_dia')),
            'tope' => $tope === null ? null : (int) $tope,
        ];
    }

    /**
     * Fecha de devolución que propone la regla: los días del préstamo desde $desde,
     * corrida al siguiente día con atención (de la biblioteca y de la ubicación de la
     * copia). Null si no hay regla o no fija plazo: la elige quien atiende.
     */
    public function fechaDevolucion(?ReglaPrestamo $regla, Carbon $desde, ?int $ubicacionId): ?Carbon
    {
        if (! $regla || $regla->dias_prestamo === null) {
            return null;
        }

        return HorarioBiblioteca::proximoDiaConAtencion($desde->copy()->startOfDay()->addDays($regla->dias_prestamo), $ubicacionId);
    }

    /**
     * Lo que el mesón necesita saber antes de prestar esta copia a esta persona: la
     * regla que se aplica, si se presta, cuántos libros tiene y puede tener, y la fecha
     * de devolución propuesta.
     */
    public function condiciones(Usuario $usuario, Ejemplar $ejemplar, Carbon $desde): array
    {
        $regla = $this->reglaParaPrestar($usuario, $ejemplar);
        $tarifa = $this->tarifaMulta($regla);

        return [
            'regla' => $regla ? [...$regla->only(['id', 'tipo_usuario', 'tipo_material_id', 'ubicacion_id', 'dias_prestamo', 'max_ejemplares', 'multa_por_dia', 'tope_multa', 'prestable']), 'descripcion' => $regla->descripcion()] : null,
            'prestable' => $regla?->prestable ?? true,
            'max_ejemplares' => $this->maxEjemplares($regla),
            'libros_en_prestamo' => $this->librosEnPrestamo($usuario->id),
            'dias_prestamo' => $regla?->dias_prestamo,
            'fecha_devolucion' => $this->fechaDevolucion($regla, $desde, $ejemplar->ubicacion_id)?->toDateString(),
            'multa_por_dia' => $tarifa['monto_dia'],
            'tope_multa' => $tarifa['tope'],
        ];
    }

    public function librosEnPrestamo(int $usuarioId): int
    {
        return Prestamo::where('usuario_id', $usuarioId)->where('tipo_item', 'libro')->where('estado', '!=', 'devuelto')->count();
    }

    /** Por qué no se le puede prestar esta copia a la persona según la regla, o null si se puede. */
    public function motivoRechazo(Usuario $usuario, Ejemplar $ejemplar): ?string
    {
        $regla = $this->reglaParaPrestar($usuario, $ejemplar);

        if ($regla && ! $regla->prestable) {
            return "Este material no se presta a domicilio ({$regla->descripcion()}).";
        }

        $maximo = $this->maxEjemplares($regla);
        $enPrestamo = $this->librosEnPrestamo($usuario->id);

        if ($enPrestamo < $maximo) {
            return null;
        }

        if ($maximo === 1) {
            return 'Este usuario ya tiene un libro prestado sin devolver — debe devolverlo antes de llevarse otro.';
        }

        return "Este usuario ya tiene {$enPrestamo} libros prestados sin devolver: el máximo a la vez es {$maximo}. Debe devolver uno antes de llevarse otro.";
    }
}
