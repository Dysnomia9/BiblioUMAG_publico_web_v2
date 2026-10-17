<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Una cuenta eliminada (SoftDeletes) desaparece de las listas, del login y de las
 * sesiones, pero su fila queda: el historial la sigue nombrando. Las relaciones hacia
 * Staff desde el historial usan withTrashed() por eso.
 */
class Staff extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'email',
        'password',
        'nombre',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /** staff.rol guarda la clave del rol (FK a roles.clave), no su id. */
    public function rolAsignado(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol', 'clave');
    }

    /** @return list<string> */
    public function permisos(): array
    {
        return $this->rolAsignado?->permisosEfectivos() ?? [];
    }

    public function puede(string $permiso): bool
    {
        return in_array($permiso, $this->permisos(), true);
    }

    /** Lo que el frontend necesita de la sesión para armar el menú y bloquear pantallas. */
    public function perfil(): array
    {
        return $this->toArray() + [
            'rol_nombre' => $this->rolAsignado?->nombre,
            'rol_acceso_total' => (bool) $this->rolAsignado?->acceso_total,
            'permisos' => $this->permisos(),
        ];
    }
}
