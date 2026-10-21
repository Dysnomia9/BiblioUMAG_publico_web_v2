<?php

namespace App\Console\Commands;

use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use Illuminate\Console\Command;

class VencerReservasLibro extends Command
{
    protected $signature = 'reservas-libro:vencer';

    protected $description = 'Cancela las reservas de libros que no se retiraron dentro del plazo y pasa cada copia al siguiente de la lista de espera';

    public function handle(ReservaLibroService $reservas): int
    {
        $vencidas = $reservas->vencerSinRetirar();

        foreach ($vencidas as $reserva) {
            $reserva->loadMissing(['libro', 'usuario']);

            Bitacora::registrar(
                'reservas_libro.vencer',
                'Venció sin retirar la reserva de «'.$reserva->libro?->titulo.'» (plazo hasta el '.$reserva->fecha_retiro?->format('d/m/Y').')',
                entidad: $reserva,
                afectado: $reserva->usuario,
            );
        }

        $this->info("{$vencidas->count()} reserva(s) de libros vencida(s) sin retirar.");

        return self::SUCCESS;
    }
}
