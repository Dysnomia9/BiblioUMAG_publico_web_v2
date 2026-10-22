<?php

namespace App\Console\Commands;

use App\Services\ReservaSalaService;
use Illuminate\Console\Command;

class LiberarReservasSalaVencidas extends Command
{
    protected $signature = 'salas:liberar-vencidas';

    protected $description = 'Marca como inasistencia las reservas de sala a las que nadie llegó dentro del plazo de confirmación';

    /**
     * Durante el día esto pasa solo cuando alguien mira las salas (ReservaSalaService::
     * liberarSiVencida()); cada noche se cierran todas, para que los reportes y el listado
     * no dependan de que alguien haya abierto la pantalla ese día.
     */
    public function handle(ReservaSalaService $salas): int
    {
        $liberadas = $salas->liberarVencidas();

        $this->info("{$liberadas} reserva(s) de sala quedaron como inasistencia.");

        return self::SUCCESS;
    }
}
