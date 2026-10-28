<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un correo adicional de un usuario (ver la migración de usuario_correos). */
class UsuarioCorreo extends Model
{
    protected $table = 'usuario_correos';

    protected $fillable = ['usuario_id', 'email'];

    protected function setEmailAttribute(string $valor): void
    {
        $this->attributes['email'] = mb_strtolower(trim($valor));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
