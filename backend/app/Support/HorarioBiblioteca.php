<?php

namespace App\Support;

use App\Models\DiaSinAtencion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Horario de atención de la biblioteca (config/biblioteca.php) y días sin atención
 * (tabla dias_sin_atencion: feriados, vacaciones y cierres, de toda la biblioteca o
 * de una ubicación).
 */
class HorarioBiblioteca
{
    /** @return array<int, array{0: string, 1: string}|null> */
    public static function semana(): array
    {
        return config('biblioteca.horario');
    }

    /** @return array{0: string, 1: string}|null [apertura, cierre] de ese día, o null si no abre. */
    public static function delDia(CarbonInterface $fecha): ?array
    {
        return self::semana()[$fecha->dayOfWeekIso] ?? null;
    }

    public static function cerrado(CarbonInterface $fecha): bool
    {
        return self::delDia($fecha) === null;
    }

    /** No atiende ese día: cerrado según el horario semanal o registrado como día sin atención. */
    public static function sinAtencion(CarbonInterface $fecha, ?int $ubicacionId = null): bool
    {
        return self::cerrado($fecha) || self::motivoSinAtencion($fecha, $ubicacionId) !== null;
    }

    /**
     * Si ese día está registrado como día sin atención (de toda la biblioteca o de esa
     * ubicación): su motivo, o «día sin atención» si no tiene. Null si no está registrado.
     */
    public static function motivoSinAtencion(CarbonInterface $fecha, ?int $ubicacionId = null): ?string
    {
        $dia = self::consultaDiasSinAtencion($ubicacionId)->whereDate('fecha', $fecha->toDateString())->first();

        return $dia ? ($dia->motivo ?: 'día sin atención') : null;
    }

    /**
     * El mismo día si la biblioteca atiende; si no, el siguiente día con atención. Con
     * ubicación, también se salta los días sin atención de esa ubicación.
     */
    public static function proximoDiaConAtencion(Carbon $fecha, ?int $ubicacionId = null): Carbon
    {
        $dia = $fecha->copy();
        // Un año de margen: más que cualquier receso, y no se queda en un bucle si alguien
        // marcara todos los días como sin atención.
        $registrados = self::fechasSinAtencion($dia, $dia->copy()->addYear(), $ubicacionId);
        for ($i = 0; $i < 366 && (self::cerrado($dia) || $registrados->contains($dia->toDateString())); $i++) {
            $dia->addDay();
        }

        return $dia;
    }

    /**
     * Días con atención después de $desde y hasta $hasta inclusive: los días de atraso
     * que se cobran (los feriados y los días que no abre no cuentan).
     */
    public static function diasConAtencionEntre(CarbonInterface $desde, CarbonInterface $hasta, ?int $ubicacionId = null): int
    {
        $dia = $desde->copy()->startOfDay()->addDay();
        $fin = $hasta->copy()->startOfDay();
        if ($dia->greaterThan($fin)) {
            return 0;
        }

        $registrados = self::fechasSinAtencion($dia, $fin, $ubicacionId);
        $dias = 0;
        for (; $dia->lessThanOrEqualTo($fin); $dia->addDay()) {
            if (! self::cerrado($dia) && ! $registrados->contains($dia->toDateString())) {
                $dias++;
            }
        }

        return $dias;
    }

    /** @return Collection<int, string> Fechas Y-m-d sin atención registradas en el rango. */
    public static function fechasSinAtencion(CarbonInterface $desde, CarbonInterface $hasta, ?int $ubicacionId = null): Collection
    {
        return self::consultaDiasSinAtencion($ubicacionId)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->toBase()
            ->pluck('fecha')
            ->map(fn ($fecha) => substr((string) $fecha, 0, 10))
            ->unique()
            ->values();
    }

    /** Los de toda la biblioteca y, con ubicación, también los de esa ubicación. */
    private static function consultaDiasSinAtencion(?int $ubicacionId)
    {
        return DiaSinAtencion::query()->where(fn ($q) => $q->whereNull('ubicacion_id')
            ->when($ubicacionId, fn ($q) => $q->orWhere('ubicacion_id', $ubicacionId)));
    }

    /** «domingo» → «domingos»; «lunes» queda igual. */
    public static function diaEnPlural(Carbon $dia): string
    {
        $nombre = $dia->locale('es')->dayName;

        return str_ends_with($nombre, 's') ? $nombre : "{$nombre}s";
    }

    /**
     * Horas enteras con atención ese día: con apertura 08:45 y cierre 20:30, de las 8
     * a las 20. Vacío si ese día no abre.
     *
     * @return list<int>
     */
    public static function horasDelDia(Carbon $fecha): array
    {
        $horario = self::semana()[$fecha->dayOfWeekIso] ?? null;

        return $horario ? range(self::hora($horario[0]), self::ultimaHora($horario[1])) : [];
    }

    /**
     * Horas con atención en algún día de la semana, para los reportes de varios días.
     *
     * @return list<int>
     */
    public static function horasDeLaSemana(): array
    {
        $abiertos = array_filter(self::semana());
        if (! $abiertos) {
            return [];
        }

        return range(
            min(array_map(fn ($h) => self::hora($h[0]), $abiertos)),
            max(array_map(fn ($h) => self::ultimaHora($h[1]), $abiertos)),
        );
    }

    private static function hora(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2);
    }

    /** Última hora entera con atención: si cierra a las 20:30, la de las 20; a las 14:00, la de las 13. */
    private static function ultimaHora(string $cierre): int
    {
        [$hora, $minutos] = array_map('intval', explode(':', $cierre));

        return $minutos > 0 ? $hora : $hora - 1;
    }
}
