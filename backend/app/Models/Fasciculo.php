<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un número de una revista (libro con la plantilla publicacion_seriada): llegó, se espera, se reclamó o falta. */
class Fasciculo extends Model
{
    use HasFactory;

    protected $table = 'fasciculos';

    public const ESTADOS = [
        'recibido' => 'Recibido',
        'esperado' => 'Esperado',
        'reclamado' => 'Reclamado',
        'faltante' => 'Faltante',
    ];

    protected $fillable = [
        'libro_id',
        'ejemplar_id',
        'enumeracion',
        'cronologia',
        'fecha_publicacion',
        'fecha_esperada',
        'fecha_recepcion',
        'estado',
        'nota',
        'referencia_externa',
    ];

    protected function casts(): array
    {
        return [
            'fecha_publicacion' => 'date:Y-m-d',
            'fecha_esperada' => 'date:Y-m-d',
            'fecha_recepcion' => 'date:Y-m-d',
        ];
    }

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class);
    }

    public function ejemplar(): BelongsTo
    {
        return $this->belongsTo(Ejemplar::class);
    }

    /** «Vol. 84, n.º 5 (mayo 2019)», para la bitácora. */
    public function descripcion(): string
    {
        $texto = collect([$this->enumeracion, $this->cronologia ? "({$this->cronologia})" : null])->filter()->implode(' ');

        return $texto !== '' ? $texto : "Fascículo {$this->id}";
    }
}
