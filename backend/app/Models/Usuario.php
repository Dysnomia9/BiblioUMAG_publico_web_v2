<?php

namespace App\Models;

use App\Support\Documento;
use App\Support\Rut;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'vigente_hasta',
        'qr_code',
    ];

    protected $hidden = [
        'password',
    ];

    // Lo que el listado y las fichas necesitan para decir por qué una cuenta no sirve.
    protected $appends = ['inactividad'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'vigente_hasta' => 'date',
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
     * «Activo» es lo que sirve hoy: la cuenta no fue desactivada Y su vigencia no pasó.
     * Así todo lo que pregunta `$usuario->activo` (entrada, portal, préstamos) respeta el
     * vencimiento sin que nadie tenga que apagar cuentas a mano. El valor guardado, sin
     * el vencimiento, es `$usuario->getRawOriginal('activo')`.
     */
    protected function activo(): Attribute
    {
        return Attribute::get(fn ($valor, array $atributos) => (bool) $valor && ! $this->venceAntesDeHoy($atributos['vigente_hasta'] ?? null));
    }

    private function venceAntesDeHoy(mixed $vigenteHasta): bool
    {
        return $vigenteHasta !== null && Carbon::parse($vigenteHasta)->endOfDay()->isPast();
    }

    /** La cuenta no fue desactivada pero su vigencia ya pasó: se arregla con una renovación. */
    public function estaVencida(): bool
    {
        return (bool) ($this->attributes['activo'] ?? false) && $this->venceAntesDeHoy($this->attributes['vigente_hasta'] ?? null);
    }

    /** null si la cuenta sirve; «vencida» o «desactivada» si no, para que el personal sepa qué hacer. */
    protected function inactividad(): Attribute
    {
        return Attribute::get(fn () => $this->activo ? null : ($this->estaVencida() ? 'vencida' : 'desactivada'));
    }

    /** Solo las cuentas que sirven hoy (activas y dentro de su vigencia). */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('usuarios.activo', true)
            ->where(fn ($q) => $q->whereNull('usuarios.vigente_hasta')->orWhere('usuarios.vigente_hasta', '>=', now()->toDateString()));
    }

    /** Las que no sirven hoy: desactivadas o vencidas. */
    public function scopeNoVigentes(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('usuarios.activo', false)->orWhere('usuarios.vigente_hasta', '<', now()->toDateString()));
    }

    /**
     * Hasta cuándo queda vigente una cuenta de este tipo que se renueva hoy, o null si ese
     * tipo no vence. Lo define la política de vigencia del tipo (Administración › Vigencia de
     * cuentas); por defecto, el 31 de marzo del año calendario siguiente (config/usuarios.php).
     */
    public static function proximoVencimiento(?Carbon $desde = null, ?string $tipo = null): ?Carbon
    {
        $desde ??= now();

        if ($politica = PoliticaVigencia::de($tipo)) {
            return $politica->vencimientoDesde($desde);
        }

        return Carbon::create($desde->year + 1, (int) config('usuarios.vigencia.mes'), (int) config('usuarios.vigencia.dia'))->startOfDay();
    }

    /**
     * Por qué no se le puede prestar ni reservar material, o null si puede. Una cuenta
     * inactiva (desactivada, o vencida como las de Horizon) no marca entrada ni entra al
     * portal; tampoco se lleva nada hasta que la renueven.
     */
    public function mensajeInactivo(): ?string
    {
        if ($this->activo) {
            return null;
        }

        return $this->estaVencida()
            ? "La cuenta de este usuario venció el {$this->vigente_hasta->format('d/m/Y')}: debe renovarse antes de llevarse o reservar material."
            : 'Este usuario se encuentra inactivo: debe ser renovado antes de llevarse o reservar material.';
    }

    /** El mismo motivo, para decírselo a la propia persona (entrada, portal). */
    public function mensajeCuentaInactiva(): string
    {
        return $this->estaVencida()
            ? "Tu cuenta venció el {$this->vigente_hasta->format('d/m/Y')}. Acércate a biblioteca para renovarla."
            : 'Tu cuenta se encuentra inactiva. Contacta a biblioteca.';
    }

    /** Correos adicionales (el principal es `email`). */
    public function correos()
    {
        return $this->hasMany(UsuarioCorreo::class);
    }

    /**
     * La persona dueña de ese correo, sea el principal o uno adicional, sin importar
     * mayúsculas. Es lo que usan el ingreso con Google y el directorio institucional.
     */
    public static function buscarPorCorreo(?string $correo): ?self
    {
        $correo = mb_strtolower(trim((string) $correo));
        if ($correo === '') {
            return null;
        }

        return static::whereRaw('lower(email) = ?', [$correo])->first()
            ?? UsuarioCorreo::whereRaw('lower(email) = ?', [$correo])->first()?->usuario;
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
