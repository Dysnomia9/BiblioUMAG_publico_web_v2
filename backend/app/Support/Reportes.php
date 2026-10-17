<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Reportes
{
    public const PERIODOS = ['dia', 'semana', 'mes', 'semestre', 'anio'];

    private const MESES = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
    ];

    private const DIAS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    /**
     * Inicio y fin del período que contiene $fecha: el día, la semana (lunes a domingo),
     * el mes, el semestre (enero–junio o julio–diciembre) o el año.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rango(Carbon $fecha, string $periodo): array
    {
        return match ($periodo) {
            'dia' => [$fecha->copy()->startOfDay(), $fecha->copy()->endOfDay()],
            'semana' => [$fecha->copy()->startOfWeek(Carbon::MONDAY), $fecha->copy()->endOfWeek(Carbon::SUNDAY)],
            'semestre' => $fecha->month <= 6
                ? [$fecha->copy()->startOfYear(), $fecha->copy()->month(6)->endOfMonth()]
                : [$fecha->copy()->month(7)->startOfMonth(), $fecha->copy()->endOfYear()],
            'anio' => [$fecha->copy()->startOfYear(), $fecha->copy()->endOfYear()],
            default => [$fecha->copy()->startOfMonth(), $fecha->copy()->endOfMonth()],
        };
    }

    /** Una fecha cualquiera dentro del período anterior (para comparar). */
    public static function anterior(Carbon $fecha, string $periodo): Carbon
    {
        return match ($periodo) {
            'dia' => $fecha->copy()->subDay(),
            'semana' => $fecha->copy()->subWeek(),
            'semestre' => $fecha->copy()->subMonthsNoOverflow(6),
            'anio' => $fecha->copy()->subYearNoOverflow(),
            default => $fecha->copy()->subMonthNoOverflow(),
        };
    }

    /**
     * Tramos del gráfico principal dentro del período: las horas de atención de ese día
     * (día), los días (semana, marcando los que la biblioteca no abre), las semanas
     * (mes) o los meses (semestre y año), incluidos los que quedan en cero.
     *
     * @return list<array{clave: string, label: string, finDeSemana: bool, cerrado: bool}>
     */
    public static function tramos(Carbon $inicio, Carbon $fin, string $periodo): array
    {
        $tramos = [];

        if ($periodo === 'dia') {
            foreach (HorarioBiblioteca::horasDelDia($inicio) as $hora) {
                $tramos[] = ['clave' => (string) $hora, 'label' => str_pad((string) $hora, 2, '0', STR_PAD_LEFT), 'finDeSemana' => false, 'cerrado' => false];
            }

            return $tramos;
        }

        if ($periodo === 'semestre' || $periodo === 'anio') {
            for ($mes = $inicio->copy()->startOfMonth(); $mes->lte($fin); $mes->addMonth()) {
                $tramos[] = ['clave' => $mes->format('Y-m'), 'label' => self::MESES[$mes->month], 'finDeSemana' => false, 'cerrado' => false];
            }

            return $tramos;
        }

        if ($periodo === 'mes') {
            // Semanas de lunes a domingo, recortadas al mes («1–6», «7–13»...). La clave es
            // el lunes de cada una (ver claveTramo()), aunque caiga en el mes anterior.
            for ($lunes = $inicio->copy()->startOfWeek(Carbon::MONDAY); $lunes->lte($fin); $lunes->addWeek()) {
                $desde = $lunes->lt($inicio) ? $inicio : $lunes;
                $hasta = $lunes->copy()->endOfWeek(Carbon::SUNDAY);
                $hasta = $hasta->gt($fin) ? $fin : $hasta;
                $tramos[] = [
                    'clave' => $lunes->toDateString(),
                    'label' => $desde->day === $hasta->day ? (string) $desde->day : $desde->day.'–'.$hasta->day,
                    'finDeSemana' => false,
                    'cerrado' => false,
                ];
            }

            return $tramos;
        }

        for ($dia = $inicio->copy()->startOfDay(); $dia->lte($fin); $dia->addDay()) {
            $tramos[] = [
                'clave' => $dia->toDateString(),
                'label' => self::DIAS[$dia->dayOfWeekIso].' '.$dia->day,
                'finDeSemana' => $dia->isWeekend(),
                'cerrado' => HorarioBiblioteca::cerrado($dia),
            ];
        }

        return $tramos;
    }

    /** Clave del tramo al que pertenece un registro (ver tramos()). */
    public static function claveTramo(Carbon $fecha, string $periodo): string
    {
        return match ($periodo) {
            'dia' => (string) $fecha->hour,
            'mes' => $fecha->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'semestre', 'anio' => $fecha->format('Y-m'),
            default => $fecha->toDateString(),
        };
    }
}
