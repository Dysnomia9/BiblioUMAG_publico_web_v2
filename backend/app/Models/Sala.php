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

    /** Máximo de integrantes de una reserva de grupo. */
    public const PERSONAS_MAXIMAS = 5;

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
     * Cuántas personas lleva una reserva de esta sala, [mínimo, máximo]: en los seminarios,
     * solo quien queda a cargo; en las demás, el grupo (config/salas.php, personas_minimas).
     *
     * @return array{0: int, 1: int}
     */
    public function personasPermitidas(): array
    {
        return $this->solo_responsable ? [1, 1] : [(int) config('salas.personas_minimas'), self::PERSONAS_MAXIMAS];
    }

    /** Por qué esa cantidad de personas no vale para esta sala, o null si vale. */
    public function mensajePersonas(int $cantidad): ?string
    {
        [$minimo, $maximo] = $this->personasPermitidas();

        return match (true) {
            $this->solo_responsable && $cantidad !== 1 => "{$this->nombre} se reserva solo a nombre de quien la pide: ingresa un único RUT, el del docente o funcionario a cargo.",
            $cantidad < $minimo => "La reserva necesita al menos {$minimo} personas.",
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
