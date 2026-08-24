<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Reportes
{
    private const MESES = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
    ];

    public static function bucketKey(Carbon $fecha, string $periodo): string
    {
        return match ($periodo) {
            'dia' => $fecha->format('Y-m-d'),
            'semana' => $fecha->format('o').'-W'.$fecha->format('W'),
            'mes' => $fecha->format('Y-m'),
            'semestre' => $fecha->year.'-S'.($fecha->month <= 6 ? '1' : '2'),
            'anio' => (string) $fecha->year,
            default => $fecha->format('Y-m-d'),
        };
    }

    public static function bucketLabel(string $key, string $periodo): string
    {
        return match ($periodo) {
            'dia' => (function () use ($key) {
                [$y, $m, $d] = explode('-', $key);

                return "{$d} ".self::MESES[(int) $m];
            })(),
            'semana' => (function () use ($key) {
                [$y, $w] = explode('-W', $key);

                return "Sem {$w}";
            })(),
            'mes' => (function () use ($key) {
                [$y, $m] = explode('-', $key);

                return self::MESES[(int) $m]." {$y}";
            })(),
            'semestre' => str_replace('-', ' ', $key),
            'anio' => $key,
            default => $key,
        };
    }
}
