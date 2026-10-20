<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Correo y clave simulados de una cuenta para el login v2 (config/login_v2.php). */
class CredencialSimulada extends Model
{
    protected $table = 'credenciales_simuladas';

    protected $fillable = ['correo', 'clave'];

    protected $hidden = ['clave'];

    protected function casts(): array
    {
        return ['clave' => 'hashed'];
    }

    /** Staff o Usuario. */
    public function cuenta(): MorphTo
    {
        return $this->morphTo();
    }
}
