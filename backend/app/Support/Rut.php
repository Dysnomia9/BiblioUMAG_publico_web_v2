<?php

namespace App\Support;

class Rut
{
    /** Limpia puntos y guion, deja el dígito verificador en mayúscula */
    public static function limpiar(string $rut): string
    {
        return strtoupper(str_replace(['.', '-'], '', $rut));
    }

    /** Calcula el dígito verificador de un RUT chileno (algoritmo módulo 11) */
    public static function digitoVerificador(int $numero): string
    {
        $suma = 0;
        $multiplo = 2;

        foreach (array_reverse(str_split((string) $numero)) as $digito) {
            $suma += ((int) $digito) * $multiplo;
            $multiplo = $multiplo === 7 ? 2 : $multiplo + 1;
        }

        $resto = 11 - ($suma % 11);

        return match ($resto) {
            11 => '0',
            10 => 'K',
            default => (string) $resto,
        };
    }

    /** Valida que el RUT (con o sin puntos/guion) tenga un dígito verificador correcto */
    public static function validar(?string $rut): bool
    {
        if (! $rut) {
            return false;
        }

        $limpio = self::limpiar($rut);

        if (strlen($limpio) < 2) {
            return false;
        }

        $cuerpo = substr($limpio, 0, -1);
        $dv = substr($limpio, -1);

        if (! ctype_digit($cuerpo)) {
            return false;
        }

        return self::digitoVerificador((int) $cuerpo) === $dv;
    }

    /** Formatea un número base a RUT con puntos y dígito verificador (12.345.678-5) */
    public static function formatear(int $numero): string
    {
        $dv = self::digitoVerificador($numero);
        $numeroFormateado = number_format($numero, 0, '', '.');

        return "{$numeroFormateado}-{$dv}";
    }
}
