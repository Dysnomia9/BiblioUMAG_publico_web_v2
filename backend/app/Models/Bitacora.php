<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una acción hecha en el sistema y quién la hizo. Ver App\Support\Bitacora para cómo se escribe. */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    // Solo created_at: una fila de la bitácora no se edita nunca.
    public const UPDATED_AT = null;

    protected $fillable = [
        'staff_id',
        'usuario_id',
        'actor_nombre',
        'modulo',
        'accion',
        'descripcion',
        'afectado',
        'entidad_tipo',
        'entidad_id',
        'datos',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
