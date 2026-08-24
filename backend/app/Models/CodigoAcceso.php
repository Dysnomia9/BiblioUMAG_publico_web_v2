<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CodigoAcceso extends Model
{
    use HasFactory;

    protected $table = 'codigo_acceso';

    protected $fillable = [
        'codigo',
        'generado_por',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'generado_por');
    }

    /** Devuelve el código de acceso vigente, generando uno si aún no existe ninguno. */
    public static function vigente(): self
    {
        return static::query()->latest('id')->first() ?? static::generar();
    }

    public static function generar(?int $staffId = null): self
    {
        return static::create([
            'codigo' => strtoupper(bin2hex(random_bytes(8))),
            'generado_por' => $staffId,
        ]);
    }
}
