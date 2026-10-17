<?php

namespace App\Models;

use App\Support\Permisos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'nivel',
        'permisos',
        'acceso_total',
    ];

    protected function casts(): array
    {
        return [
            'permisos' => 'array',
            'acceso_total' => 'boolean',
            'nivel' => 'integer',
        ];
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'rol', 'clave');
    }

    /**
     * El rol con acceso total no guarda su lista: tiene todo el catálogo, incluidos
     * permisos que se agreguen en el futuro. En el resto se descartan claves que ya no
     * existan en el catálogo (ej. un permiso renombrado en una versión nueva).
     *
     * @return list<string>
     */
    public function permisosEfectivos(): array
    {
        if ($this->acceso_total) {
            return Permisos::claves();
        }

        return array_values(array_intersect(Permisos::claves(), $this->permisos ?? []));
    }
}
