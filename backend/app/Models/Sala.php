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
    ];

    protected function casts(): array
    {
        return [
            'solo_administracion' => 'boolean',
        ];
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
