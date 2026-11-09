<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Fechas en que el tiempo para presentarse a una sala es otro, como la semana de pruebas. */
class PeriodoPlazoSala extends Model
{
    public const MINUTOS_MINIMOS = 1;

    /** La reserva más corta dura 30 minutos (config/salas.php): el plazo no puede pasarse de su término. */
    public const MINUTOS_MAXIMOS = 30;

    protected $table = 'periodos_plazo_sala';

    protected $fillable = ['desde', 'hasta', 'minutos', 'motivo'];

    protected function casts(): array
    {
        return [
            'desde' => 'date:Y-m-d',
            'hasta' => 'date:Y-m-d',
            'minutos' => 'integer',
        ];
    }

    /** Los minutos para presentarse que rigen para una reserva de ese día. */
    public static function minutosPara(CarbonInterface|string $fecha): int
    {
        $dia = Carbon::parse($fecha)->toDateString();

        return static::whereDate('desde', '<=', $dia)->whereDate('hasta', '>=', $dia)->value('minutos')
            ?? ConfiguracionInstitucional::actual()->plazo_confirmacion_sala;
    }

    /** «del 09/11/2026 al 13/11/2026 (Semana de pruebas)», para los avisos y la bitácora. */
    public function descripcion(): string
    {
        return 'del '.$this->desde->format('d/m/Y').' al '.$this->hasta->format('d/m/Y').($this->motivo ? " ({$this->motivo})" : '');
    }
}
