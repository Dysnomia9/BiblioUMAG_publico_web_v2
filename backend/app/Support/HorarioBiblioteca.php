<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Horario de atención de la biblioteca (config/biblioteca.php). */
class HorarioBiblioteca
{
    /** @return array<int, array{0: string, 1: string}|null> */
    public static function semana(): array
    {
        return config('biblioteca.horario');
    }

    /** @return array{0: string, 1: string}|null [apertura, cierre] de ese día, o null si no abre. */
    public static function delDia(Carbon $fecha): ?array
    {
        return self::semana()[$fecha->dayOfWeekIso] ?? null;
    }

    public static function cerrado(Carbon $fecha): bool
    {
        return self::delDia($fecha) === null;
    }

    /** El mismo día si la biblioteca atiende; si no, el siguiente día con atención. */
    public static function proximoDiaConAtencion(Carbon $fecha): Carbon
    {
        $dia = $fecha->copy();
        for ($i = 0; $i < 7 && self::cerrado($dia); $i++) {
            $dia->addDay();
        }

        return $dia;
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
