<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Los avisos por correo a los usuarios (reserva lista para retirar, multa generada). Salen
 * cuando lo que los motiva ya quedó guardado (al terminar la transacción, si hay una
 * abierta), y si el servidor de correo falla o no responde se anota en el log sin
 * deshacer ni interrumpir nada: una devolución no puede fallar por un correo.
 */
final class Notificar
{
    public static function enviar(?Usuario $persona, Notification $aviso): void
    {
        if (! $persona?->email) {
            return;
        }

        // rescue() deja el error en storage/logs/laravel.log y sigue.
        DB::afterCommit(fn () => rescue(fn () => $persona->notify($aviso)));
    }
}
