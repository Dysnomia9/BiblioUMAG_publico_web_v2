<?php

namespace App\Support;

/**
 * El correo simulado del login v2: dos letras del primer nombre y seis del primer
 * apellido, sin tildes ni espacios. «Ignacio Contreras» → «igcontre@umag.cl»;
 * «Diego Muñoz» → «dimunoz@umag.cl».
 */
final class CorreoSimulado
{
    public static function base(?string $nombre, ?string $apellido): string
    {
        $primera = fn (?string $texto) => explode(' ', Texto::clave($texto))[0] ?? '';
        $soloLetras = fn (string $texto) => preg_replace('/[^a-z]/', '', $texto);

        $base = substr($soloLetras($primera($nombre)), 0, 2).substr($soloLetras($primera($apellido)), 0, 6);

        return $base !== '' ? $base : 'usuario';
    }

    /**
     * El primer correo libre para esa persona: «igcontre», y si ya lo tiene otra,
     * «igcontre2», «igcontre3»…
     *
     * @param  callable(string): bool  $tomado
     */
    public static function libre(?string $nombre, ?string $apellido, callable $tomado): string
    {
        $base = self::base($nombre, $apellido);
        $dominio = config('login_v2.dominio');

        for ($n = 1; ; $n++) {
            $correo = $base.($n > 1 ? $n : '')."@{$dominio}";
            if (! $tomado($correo)) {
                return $correo;
            }
        }
    }
}
