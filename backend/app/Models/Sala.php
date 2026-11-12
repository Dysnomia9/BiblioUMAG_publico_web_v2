<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sala extends Model
{
    use HasFactory;

    protected $table = 'salas';

    protected $fillable = [
        'nombre',
        'codigo',
        'capacidad',
        'tipo',
        // No se presta a estudiantes ni se ofrece en el portal (Sala de Seminarios): la
        // reserva quien tiene el permiso salas.administracion.
        'solo_administracion',
        // Se reserva a nombre de quien la pide (el docente o funcionario a cargo), sin
        // anotar a cada asistente: los seminarios.
        'solo_responsable',
        // Dada de baja (false): ya no se ofrece ni se reserva, y su historial se conserva.
        'activa',
    ];

    public const TIPOS = ['logia', 'sala'];

    protected $attributes = ['activa' => true];

    protected function casts(): array
    {
        return [
            'solo_administracion' => 'boolean',
            'solo_responsable' => 'boolean',
            'activa' => 'boolean',
        ];
    }

    /** Las logias por su código (L16 queda después de L15) y luego las salas con nombre propio. */
    public function scopeEnOrden(Builder $salas): Builder
    {
        return $salas->orderByRaw("tipo = 'logia' desc")->orderByRaw("case when tipo = 'logia' then codigo end")->orderBy('id');
    }

    /** Regla de validación para reservar en una sala: que exista y no esté dada de baja. */
    public static function reglaDisponible(): \Closure
    {
        return function (string $campo, mixed $id, \Closure $falla) {
            if (! is_numeric($id) || ! static::whereKey($id)->where('activa', true)->exists()) {
                $falla('Esa sala no existe o está dada de baja.');
            }
        };
    }

    /**
     * Cuántas personas lleva una reserva de esta sala hecha en el mesón, [mínimo, máximo]: en
     * los seminarios, solo quien queda a cargo; en las demás, lo que permite el mesón
     * (config/salas.php). El portal tiene su propio rango: personasDelPortal().
     *
     * @return array{0: int, 1: int}
     */
    public function personasPermitidas(): array
    {
        if ($this->solo_responsable) {
            return [1, 1];
        }
        [$minimo, $maximo] = self::personasDelMeson();

        return [$this->minimoPropio($minimo, $maximo), $maximo];
    }

    /**
     * Cuántas personas lleva una reserva de esta sala hecha en el portal, [mínimo, máximo]:
     * el rango del portal (config/salas.php), con el mínimo de las logias si está encendido.
     *
     * @return array{0: int, 1: int}
     */
    public function personasEnElPortal(): array
    {
        [$minimo, $maximo] = self::personasDelPortal();

        return [$this->minimoPropio($minimo, $maximo), $maximo];
    }

    /**
     * El mínimo de personas de todas las logias (Administración › Salas), o null si está
     * apagado y valen los de config/salas.php.
     */
    public static function minimoLogias(): ?int
    {
        $configuracion = ConfiguracionInstitucional::actual();

        return $configuracion->minimo_personas_logias_activo ? $configuracion->minimo_personas_logias : null;
    }

    /** El mínimo de las logias, si está encendido, sin pasar del máximo del rango (si no, el del rango). */
    private function minimoPropio(int $minimo, int $maximo): int
    {
        $logias = $this->tipo === 'logia' ? self::minimoLogias() : null;

        return $logias === null ? $minimo : min($logias, $maximo);
    }

    /** @return array{0: int, 1: int} */
    public static function personasDelMeson(): array
    {
        return [(int) config('salas.personas_minimas_meson'), (int) config('salas.personas_maximas_meson')];
    }

    /** @return array{0: int, 1: int} */
    public static function personasDelPortal(): array
    {
        return [(int) config('salas.personas_minimas'), (int) config('salas.personas_maximas')];
    }

    /** Por qué esa cantidad de personas no vale para esta sala, o null si vale. */
    public function mensajePersonas(int $cantidad): ?string
    {
        [$minimo, $maximo] = $this->personasPermitidas();

        return match (true) {
            $this->solo_responsable && $cantidad !== 1 => "{$this->nombre} se reserva solo a nombre de quien la pide: ingresa un único RUT, el del docente o funcionario a cargo.",
            $cantidad < $minimo => $minimo === 1 ? 'La reserva necesita al menos una persona.' : "La reserva necesita al menos {$minimo} personas.",
            $cantidad > $maximo => "La reserva admite como máximo {$maximo} personas.",
            default => null,
        };
    }

    /** Para el portal: por qué no se puede reservar esta sala, o null si se puede. */
    public static function mensajeSoloAdministracion(int|string $salaId): ?string
    {
        $sala = static::find($salaId);

        return $sala?->solo_administracion
            ? "{$sala->nombre} no se presta a estudiantes ni se reserva desde el portal: la gestiona la administración de la biblioteca."
            : null;
    }

    /**
     * La sala de un código escrito en el mesón: «L04», «l4», «4», «Logia 4» o «SEM».
     */
    public static function porCodigo(string $texto): ?self
    {
        $codigo = self::normalizarCodigo($texto);

        return $codigo === '' ? null : static::where('codigo', $codigo)->first();
    }

    /** Un código como se guarda: en mayúsculas y sin espacios. Un número solo es el de una logia: «l4» y «4» son L04. */
    public static function normalizarCodigo(string $texto): string
    {
        $codigo = mb_strtoupper(preg_replace('/\s+/', '', $texto));

        return preg_match('/^(?:L|LOGIA)?0*(\d{1,2})$/', $codigo, $m) ? 'L'.str_pad($m[1], 2, '0', STR_PAD_LEFT) : $codigo;
    }

    public function reservas()
    {
        return $this->hasMany(Reserva::class);
    }
}
