<?php

namespace App\Console\Commands;

use App\Models\Sala;
use Illuminate\Console\Command;

class ImportarCodigosLogia extends Command
{
    protected $signature = 'horizon:codigos-logia';

    protected $description = 'Actualiza salas.codigo_barras a partir del mapeo definido en config/horizon_barcodes.php';

    public function handle(): int
    {
        $mapa = config('horizon_barcodes.logias', []);

        if (empty($mapa)) {
            $this->warn('config/horizon_barcodes.php no tiene códigos de logia cargados todavía.');

            return self::SUCCESS;
        }

        $actualizadas = 0;
        foreach ($mapa as $codigo => $nombreSala) {
            $actualizadas += Sala::where('nombre', $nombreSala)
                ->where('tipo', 'logia')
                ->update(['codigo_barras' => $codigo]);
        }

        $this->info("{$actualizadas} logia(s) actualizadas con código de barras.");

        return self::SUCCESS;
    }
}
