<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfiguracionInstitucional extends Model
{
    use HasFactory;

    protected $table = 'configuracion_institucional';

    protected $fillable = ['jefe_unidad_nombre', 'jefe_unidad_cargo'];

    /** Siempre hay exactamente una fila (id=1, sembrada por la migración) — mismo patrón que CodigoAcceso::vigente(). */
    public static function actual(): self
    {
        return static::firstOrFail();
    }
}
