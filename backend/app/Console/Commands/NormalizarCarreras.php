<?php

namespace App\Console\Commands;

use App\Models\Carrera;
use App\Support\Bitacora;
use App\Support\Carreras;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizarCarreras extends Command
{
    protected $signature = 'usuarios:normalizar-carreras
        {--aplicar : Guarda los cambios. Sin esto solo muestra qué cambiaría}
        {--csv= : Dónde escribir el detalle (por defecto storage/app/carreras-normalizadas-AAAAMMDD-HHMMSS.csv)}';

    protected $description = 'Escribe bien los nombres de carrera de los usuarios (tildes, mayúsculas y abreviaturas de Horizon). Por defecto solo muestra el cambio';

    public function handle(): int
    {
        $actuales = DB::table('usuarios')->whereNotNull('carrera')->selectRaw('carrera, count(*) as n')->groupBy('carrera')->orderBy('carrera')->pluck('n', 'carrera');

        $cambios = [];
        foreach ($actuales as $antes => $n) {
            $despues = Carreras::normalizar($antes);
            if ($despues !== $antes) {
                $cambios[] = ['antes' => $antes, 'despues' => $despues, 'usuarios' => (int) $n];
            }
        }

        $distintasAntes = $actuales->count();
        $distintasDespues = $actuales->keys()->map(fn ($c) => Carreras::normalizar($c))->unique()->count();

        $this->table(['Antes', 'Después', 'Usuarios'], array_map(fn ($c) => [$c['antes'], $c['despues'], $c['usuarios']], $cambios));
        $this->info(count($cambios)." nombre(s) cambian de {$distintasAntes}; quedan {$distintasDespues} carreras distintas.");

        $ruta = $this->option('csv') ?: storage_path('app/carreras-normalizadas-'.now()->format('Ymd-His').'.csv');
        $archivo = fopen($ruta, 'w');
        fwrite($archivo, "\xEF\xBB\xBF");
        fputcsv($archivo, ['antes', 'despues', 'usuarios'], ';');
        foreach ($cambios as $c) {
            fputcsv($archivo, [$c['antes'], $c['despues'], $c['usuarios']], ';');
        }
        fclose($archivo);
        $this->line("Detalle: {$ruta}");

        if (! $this->option('aplicar')) {
            $this->warn('Es una simulación: no se guardó nada. Agrega --aplicar para hacer el cambio.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cambios) {
            foreach ($cambios as $c) {
                DB::table('usuarios')->where('carrera', $c['antes'])->update(['carrera' => $c['despues']]);
            }

            // El catálogo de carreras (si trae nombres de Horizon) se escribe igual; si el nombre
            // corregido ya existe, se deja como está para no chocar con el nombre único.
            foreach (Carrera::all() as $carrera) {
                $nombre = Carreras::normalizar($carrera->nombre);
                if ($nombre !== $carrera->nombre && ! Carrera::where('nombre', $nombre)->exists()) {
                    $carrera->update(['nombre' => $nombre]);
                }
            }

            Bitacora::registrar(
                'usuarios.normalizar_carreras',
                'Escribió bien los nombres de carrera: '.count($cambios).' nombres corregidos',
                datos: ['cambios' => count($cambios), 'usuarios' => array_sum(array_column($cambios, 'usuarios'))],
            );
        });

        $this->info('Listo: los cambios quedaron guardados y en la bitácora.');

        return self::SUCCESS;
    }
}
