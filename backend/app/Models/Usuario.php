<?php

namespace App\Models;

use App\Support\Documento;
use App\Support\Rut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'rut',
        'tipo_documento',
        'pais',
        'nombre',
        'apellido',
        'email',
        'password',
        'tipo',
        'carrera',
        'anio_ingreso',
        'sexo',
        'activo',
        'qr_code',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Busca por RUT, pasaporte o DNI sin importar cómo venga escrito (con o sin
     * puntos, guion o espacios, en mayúsculas o minúsculas).
     */
    public function scopePorIdentificador(Builder $query, ?string $identificador): Builder
    {
        // «08733712-K» es el mismo RUT que «8.733.712-K», que se guarda sin el cero.
        $normalizado = Documento::normalizar($identificador);
        $sinCeros = ltrim($normalizado, '0');
        $valores = $sinCeros !== '' && $sinCeros !== $normalizado && Rut::validar($sinCeros) ? [$normalizado, $sinCeros] : [$normalizado];

        return $query->whereRaw(Documento::sql('rut').' in ('.implode(',', array_fill(0, count($valores), '?')).')', $valores);
    }

    public static function buscarPorIdentificador(?string $identificador): ?self
    {
        return Documento::normalizar($identificador) === '' ? null : static::porIdentificador($identificador)->first();
    }

    /** «Valentina Soto (RUT 11.111.111-1)» — así aparece la persona en la bitácora. */
    public function descripcion(): string
    {
        return trim("{$this->nombre} {$this->apellido}").' ('.Documento::describir($this->rut, $this->tipo_documento, $this->pais).')';
    }

    /**
     * Por qué no se le puede prestar ni reservar material, o null si puede. Una cuenta
     * inactiva (por ejemplo, porque su registro venció en Horizon) no marca entrada ni
     * entra al portal; tampoco se lleva nada hasta que la habiliten.
     */
    public function mensajeInactivo(): ?string
    {
        return $this->activo ? null : 'Este usuario se encuentra inactivo: debe ser habilitado antes de llevarse o reservar material.';
    }

    public function entradas()
    {
        return $this->hasMany(Entrada::class);
    }

    public function prestamos()
    {
        return $this->hasMany(Prestamo::class);
    }

    public function reservas()
    {
        return $this->hasMany(Reserva::class);
    }

    public function multas()
    {
        return $this->hasMany(Multa::class);
    }
}
