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
        'codigo',
        'capacidad',
        'tipo',
        // No se presta a estudiantes ni se ofrece en el portal (Sala de Seminarios): la
        // reserva quien tiene el permiso salas.administracion.
        'solo_administracion',
        // Se reserva a nombre de quien la pide (el docente o funcionario a cargo), sin
        // anotar a cada asistente: los seminarios.
        'solo_responsable',
    ];

    /** Máximo de integrantes de una reserva de grupo. */
    public const PERSONAS_MAXIMAS = 5;

    protected function casts(): array
    {
        return [
            'solo_administracion' => 'boolean',
            'solo_responsable' => 'boolean',
        ];
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
     * La sala de un código escrito en el mesón: «L04», «l4», «4», «Logia 4» o «SEM». Un
     * número solo es el de una logia.
     */
    public static function porCodigo(string $texto): ?self
    {
        $codigo = mb_strtoupper(preg_replace('/\s+/', '', $texto));
        if (preg_match('/^(?:L|LOGIA)?0*(\d{1,2})$/', $codigo, $m)) {
            $codigo = 'L'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return $codigo === '' ? null : static::where('codigo', $codigo)->first();
    }

    public function reservas()
    {
        return $this->hasMany(Reserva::class);
    }
}
