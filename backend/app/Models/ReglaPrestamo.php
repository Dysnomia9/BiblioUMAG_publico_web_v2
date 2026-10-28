<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plazo, máximo a la vez y multa para un tipo de usuario, tipo de material y
 * ubicación; null en cualquiera de los tres vale para todos. Cuál se aplica a cada
 * préstamo lo decide App\Services\ReglaPrestamoService.
 */
class ReglaPrestamo extends Model
{
    use HasFactory;

    protected $table = 'reglas_prestamo';

    /** Los de usuarios.tipo. */
    public const TIPOS_USUARIO = ['estudiante', 'docente', 'funcionario'];

    protected $fillable = [
        'tipo_usuario',
        'tipo_material_id',
        'ubicacion_id',
        'dias_prestamo',
        'max_ejemplares',
        'multa_por_dia',
        'tope_multa',
        'prestable',
    ];

    protected function casts(): array
    {
        return [
            'dias_prestamo' => 'integer',
            'max_ejemplares' => 'integer',
            'multa_por_dia' => 'integer',
            'tope_multa' => 'integer',
            'prestable' => 'boolean',
        ];
    }

    public function tipoMaterial(): BelongsTo
    {
        return $this->belongsTo(TipoMaterial::class);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    /** «Estudiantes · Revista · Hemeroteca», con «todos» donde la regla no distingue. */
    public function descripcion(): string
    {
        $this->loadMissing(['tipoMaterial:id,nombre', 'ubicacion:id,nombre']);

        return implode(' · ', [
            $this->tipo_usuario ? ucfirst($this->tipo_usuario).'s' : 'Todos los usuarios',
            $this->tipoMaterial?->nombre ?? 'todo material',
            $this->ubicacion?->nombre ?? 'toda ubicación',
        ]);
    }
}
