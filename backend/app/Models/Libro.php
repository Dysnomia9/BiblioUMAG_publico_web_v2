<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Libro extends Model
{
    use HasFactory;

    protected $table = 'libros';

    /** Campos bibliográficos que se catalogan (ver App\Support\FichaBibliografica). */
    public const CAMPOS_BIBLIOGRAFICOS = [
        'titulo', 'isbn', 'clasificacion', 'coleccion', 'editorial',
        'anio_publicacion', 'tipo_material_id', 'nota_interna', 'nota_publica',
        'titulo_alternativo', 'edicion', 'lugar_publicacion', 'descripcion_fisica',
        'entidad_corporativa', 'reunion', 'entidad_secundaria', 'materias_personales',
        'materias_geograficas', 'url_acceso', 'issn', 'designacion_volumen',
        'nota_tesis', 'profesor_guia', 'resumen', 'audiencia',
        'idioma', 'serie', 'nota_bibliografia', 'frecuencia', 'nota_idioma', 'nota_local', 'contenido',
    ];

    protected $fillable = [...self::CAMPOS_BIBLIOGRAFICOS, 'catalogado_por_staff_id'];

    protected function casts(): array
    {
        return [
            'anio_publicacion' => 'integer',
            // Solo la escribe la migración desde Horizon: no está en $fillable.
            'fecha_ingreso_horizon' => 'date:Y-m-d',
        ];
    }

    public function ejemplares(): HasMany
    {
        return $this->hasMany(Ejemplar::class);
    }

    /** Todos los préstamos de todas sus copias. */
    public function prestamos(): HasManyThrough
    {
        return $this->hasManyThrough(Prestamo::class, Ejemplar::class);
    }

    /** En orden de catalogación: el primero es el autor principal (100), el resto, secundarios (700). */
    public function autores(): BelongsToMany
    {
        return $this->belongsToMany(Autor::class, 'libro_autor')->withPivot('orden')->orderByPivot('orden');
    }

    public function catalogadoPor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'catalogado_por_staff_id')->withTrashed();
    }

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(Categoria::class, 'libro_categoria');
    }

    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'libro_carrera');
    }

    /** Números recibidos y esperados, si es una revista (plantilla publicacion_seriada). */
    public function fasciculos(): HasMany
    {
        return $this->hasMany(Fasciculo::class);
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaLibro::class);
    }

    public function tipoMaterial(): BelongsTo
    {
        return $this->belongsTo(TipoMaterial::class);
    }
}
