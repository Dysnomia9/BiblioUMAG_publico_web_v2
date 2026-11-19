<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una pestaña del mesón de salas: agrupa salas (un piso, el hospital, las de la
 * administración). Cada sala va en una sola pestaña o en ninguna («Otras»).
 */
class PestanaSala extends Model
{
    protected $table = 'pestanas_salas';

    protected $fillable = ['nombre', 'orden', 'apartada'];

    protected $attributes = ['orden' => 0, 'apartada' => false];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'apartada' => 'boolean'];
    }

    public function salas(): HasMany
    {
        return $this->hasMany(Sala::class, 'pestana_id');
    }

    /** Primero las de siempre, después las apartadas; cada grupo por su orden. */
    public function scopeEnOrden(Builder $pestanas): Builder
    {
        return $pestanas->orderBy('apartada')->orderBy('orden')->orderBy('id');
    }

    /**
     * Las pestañas de partida, como venían fijas en el mesón: un piso por cada piso del plano
     * (frontend/src/utils/mapaSalas.ts), la del hospital (HCM) y, apartada, la de la administración
     * (toda sala solo_administracion). Solo crea las que faltan y solo asigna salas que no
     * tienen pestaña: lo que ya se ajustó en el mesón no se toca.
     */
    public static function crearIniciales(): void
    {
        $logias = fn (array $numeros) => array_map(fn ($n) => 'L'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), $numeros);
        $iniciales = [
            ['Piso 1', false, [...$logias(range(1, 13)), 'AGA', 'POS', 'TPG', 'SM1']],
            ['Piso 2', false, [...$logias(range(22, 30)), 'COO', 'SM2']],
            ['HCM', false, ['HCM1', 'HCM2']],
            ['Administración', true, null],
        ];

        foreach ($iniciales as $orden => [$nombre, $apartada, $codigos]) {
            $pestana = static::firstOrCreate(['nombre' => $nombre], ['orden' => $orden, 'apartada' => $apartada]);
            $sinPestana = Sala::whereNull('pestana_id');
            $codigos === null
                ? $sinPestana->where('solo_administracion', true)->update(['pestana_id' => $pestana->id])
                : $sinPestana->where('solo_administracion', false)->whereIn('codigo', $codigos)->update(['pestana_id' => $pestana->id]);
        }
    }
}
