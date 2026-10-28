<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cuánto dura la cuenta de cada tipo de usuario al renovarla (ver la migración de
 * politicas_vigencia). Si falta la fila de un tipo, vale la regla de config/usuarios.php.
 */
class PoliticaVigencia extends Model
{
    public const MODOS = ['fecha_fija', 'duracion', 'sin_vencimiento'];

    public const TIPOS = ['estudiante', 'docente', 'funcionario'];

    protected $table = 'politicas_vigencia';

    protected $fillable = ['tipo', 'modo', 'mes', 'dia', 'anios'];

    protected static function booted(): void
    {
        static::saved(fn () => self::olvidar());
    }

    /** @var Collection<string, PoliticaVigencia>|null */
    private static ?Collection $memoria = null;

    /** Las tres políticas por tipo; se leen una vez por petición. */
    public static function todas(): Collection
    {
        return self::$memoria ??= static::all()->keyBy('tipo');
    }

    public static function olvidar(): void
    {
        self::$memoria = null;
    }

    public static function de(?string $tipo): ?self
    {
        return self::todas()->get($tipo ?? 'estudiante');
    }

    /**
     * Hasta cuándo queda vigente una cuenta renovada en `$desde`, o null si este tipo
     * no vence. Fecha fija: ese día del año calendario SIGUIENTE (renovar en octubre de
     * 2026 vence el 31/03/2027; renovar en marzo de 2027, el 31/03/2028). Duración: esa
     * cantidad de años desde la renovación.
     */
    public function vencimientoDesde(Carbon $desde): ?Carbon
    {
        return match ($this->modo) {
            'sin_vencimiento' => null,
            'duracion' => $desde->copy()->startOfDay()->addYears(max(1, (int) $this->anios)),
            default => Carbon::create($desde->year + 1, (int) ($this->mes ?: config('usuarios.vigencia.mes')), (int) ($this->dia ?: config('usuarios.vigencia.dia')))->startOfDay(),
        };
    }

    /** Frase corta para decir qué hace esta política. */
    public function descripcion(): string
    {
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return match ($this->modo) {
            'sin_vencimiento' => 'No vence',
            'duracion' => max(1, (int) $this->anios) === 1 ? 'Vence un año después de renovar' : 'Vence '.max(1, (int) $this->anios).' años después de renovar',
            default => "Vence el {$this->dia} de {$meses[(int) $this->mes]} del año siguiente",
        };
    }
}
