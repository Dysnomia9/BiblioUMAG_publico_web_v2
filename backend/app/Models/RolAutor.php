<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un papel de una persona en una obra (700 $e): ver App\Support\RolesAutor. */
class RolAutor extends Model
{
    protected $table = 'roles_autor';

    protected $fillable = ['clave', 'nombre', 'abreviatura', 'plural', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];
}
