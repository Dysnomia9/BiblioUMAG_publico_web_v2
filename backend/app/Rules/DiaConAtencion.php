<?php

namespace App\Rules;

use App\Support\HorarioBiblioteca;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * La fecha tiene que caer en un día en que la biblioteca atiende: abierta según
 * config/biblioteca.php y sin un día sin atención registrado (de toda la biblioteca
 * o, si se indica, de la ubicación de la copia).
 */
class DiaConAtencion implements ValidationRule
{
    /** @param  string  $para  Cierre del mensaje: «para la devolución», «para el retiro». */
    public function __construct(private string $para, private ?int $ubicacionId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $dia = Carbon::parse($value);
        } catch (\Throwable) {
            return; // El formato ya lo revisa la regla 'date'.
        }

        if (HorarioBiblioteca::cerrado($dia)) {
            $fail('La biblioteca no atiende los '.HorarioBiblioteca::diaEnPlural($dia).": elige otro día {$this->para}.");

            return;
        }

        if ($motivo = HorarioBiblioteca::motivoSinAtencion($dia, $this->ubicacionId)) {
            $fail('La biblioteca no atiende el '.$dia->format('d/m/Y')." ({$motivo}): elige otro día {$this->para}.");
        }
    }
}
