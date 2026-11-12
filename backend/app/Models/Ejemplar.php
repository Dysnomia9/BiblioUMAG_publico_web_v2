<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ejemplar extends Model
{
    use HasFactory;

    protected $table = 'ejemplares';

    /** Cómo llegó la copia a la biblioteca (columna forma_adquisicion). */
    public const FORMAS_ADQUISICION = ['compra', 'donacion', 'canje'];

    protected $fillable = [
        'libro_id',
        'numero_copia',
        'codigo_barras',
        'disponible',
        'estado_proceso',
        'estado_personalizado_id',
        'ubicacion_id',
        'volumen',
        // Tomo y copia de ese tomo («t.1» y 3 en «t.1 c.3»): ver App\Support\TomoEjemplar.
        'tomo',
        'copia_tomo',
        'precio',
        'forma_adquisicion',
        'fecha_inventario',
        'nota_publica',
        'nota_interna',
        'coleccion',
        // El número de registro de la copia en Horizon (item.notes).
        'numero_registro',
    ];

    /** La copia como se rotula (lomo, listados): ver getEtiquetaCopiaAttribute(). */
    protected $appends = ['etiqueta_copia'];

    /**
     * «c.2» como se rotula la copia: la «c.N» que figura en la signatura y en el lomo
     * (copia_tomo), o nada si no tiene. Las de Horizon traen la suya; las creadas aquí, la que
     * escribió quien catalogó (o la que se calculó). numero_copia es interno y no se muestra.
     */
    public function getEtiquetaCopiaAttribute(): ?string
    {
        $atributos = $this->getAttributes();
        // Cargada con solo algunas columnas: sin saber si vino de Horizon no se puede decir su
        // «c.N», y numero_copia (interno) daría otro número.
        if (! array_key_exists('copia_tomo', $atributos) || ! array_key_exists('fecha_ingreso_horizon', $atributos)) {
            return null;
        }
        if (! empty($atributos['copia_tomo'])) {
            return "c.{$atributos['copia_tomo']}";
        }
        // Sin «c.»: de Horizon sin número, la primera de un tomo («t.4» sola) o una que se catalogó sin número
        return null;
    }

    /** Para los mensajes: «copia 2» (la de Horizon y el lomo) o «copia sin número». */
    public function copiaEnTexto(): string
    {
        return ($etiqueta = $this->etiqueta_copia) ? 'copia '.preg_replace('/^c\.\s*/i', '', $etiqueta) : 'copia sin número';
    }

    protected function casts(): array
    {
        return [
            'disponible' => 'boolean',
            'precio' => 'decimal:2',
            'fecha_inventario' => 'date',
            // Solo la escribe la migración desde Horizon: no está en $fillable.
            'fecha_ingreso_horizon' => 'date:Y-m-d',
        ];
    }

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class);
    }

    public function estadoPersonalizado(): BelongsTo
    {
        return $this->belongsTo(EstadoLibroPersonalizado::class, 'estado_personalizado_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class);
    }

    public function reservasLibro(): HasMany
    {
        return $this->hasMany(ReservaLibro::class);
    }

    /** El número de revista que es esta copia, si es un fascículo prestable. */
    public function fasciculo(): HasOne
    {
        return $this->hasOne(Fasciculo::class);
    }

    /** Si la copia es de otra biblioteca, que nos la prestó (préstamo interbibliotecario). */
    public function prestamoInterbibliotecario(): HasOne
    {
        return $this->hasOne(PrestamoInterbibliotecario::class);
    }

    /** Su préstamo interbibliotecario, si todavía no se devuelve a la biblioteca propietaria. */
    public function prestamoInterbibliotecarioVigente(): ?PrestamoInterbibliotecario
    {
        $prestamo = $this->prestamoInterbibliotecario;

        return $prestamo && ! $prestamo->devuelto_el ? $prestamo->setRelation('ejemplar', $this) : null;
    }

    public function historialEstado(): HasMany
    {
        return $this->hasMany(EjemplarEstadoHistorial::class);
    }

    /**
     * Un código corto del sistema: 8 dígitos desde 10000001, los de la secuencia del código de
     * lomo. Los cortos de Horizon empiezan con 0 («02025988») y no son de estos.
     */
    public static function esCodigoCorto(string $codigo): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{7}$/', trim($codigo));
    }

    /**
     * Busca la copia por cualquiera de sus dos códigos de barras: el de 14 dígitos o el
     * de 8 de la etiqueta de lomo (codigo_lomo, lo asigna la base al crear la copia).
     */
    public function scopePorCodigo(Builder $query, string $codigo): Builder
    {
        $codigo = trim($codigo);
        $codigos = self::variantesCodigo($codigo);

        return $query->where(fn ($q) => $q->whereIn('codigo_barras', $codigos)->orWhere('codigo_lomo', $codigo));
    }

    /**
     * El código tal cual y, si es numérico corto (ej. 02025988), también su forma larga
     * de 14 dígitos con el prefijo 3 (30000002025988): en el sistema se usaban ambas.
     */
    public static function variantesCodigo(string $codigo): array
    {
        $codigo = trim($codigo);
        $variantes = [$codigo];

        if (preg_match('/^\d{1,13}$/', $codigo)) {
            $variantes[] = '3'.str_pad($codigo, 13, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($variantes));
    }

    /** La colección de la copia; si no tiene, la del libro. */
    public function coleccionEfectiva(): ?string
    {
        return $this->coleccion ?: $this->libro?->coleccion;
    }

    /** "Título (Copia N)" con la copia de Horizon y el lomo («c.N»), o el título tal cual si no tiene. */
    public function tituloConCopia(): string
    {
        $titulo = $this->libro->titulo;
        $etiqueta = $this->etiqueta_copia;

        return $etiqueta ? "{$titulo} (Copia ".preg_replace('/^c\.\s*/i', '', $etiqueta).')' : $titulo;
    }

    /**
     * El número de la próxima copia de un libro. Como en Horizon, la primera no lleva «c.»
     * (es la 0) y las demás son c.1, c.2…: el mayor más uno. Copias distintas pueden
     * compartir número (dos sin «c.», o registros de Horizon fusionados): se distinguen por
     * el código de barras.
     */
    public static function siguienteNumero(int $libroId): int
    {
        $mayor = static::where('libro_id', $libroId)->max('numero_copia');

        return $mayor === null ? 0 : (int) $mayor + 1;
    }
}
