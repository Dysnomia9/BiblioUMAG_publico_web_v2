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
    ];

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
