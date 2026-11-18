<?php

namespace App\Console\Commands;

use App\Services\ExportarSistemaService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ExportarSistema extends Command
{
    protected $signature = 'sistema:exportar
        {--mes= : Mes AAAA-MM; por defecto, el mes actual de la biblioteca}
        {--archivo= : Ruta del JSON; por defecto, storage/app/traspasos/biblioumag-MES-FECHA.json}';

    protected $description = 'Exporta datos del nuevo sistema y sus dependencias, excluyendo el historial de Horizon';

    public function handle(ExportarSistemaService $servicio): int
    {
        $temporal = null;
        try {
            $mes = ExportarSistemaService::mes($this->option('mes'))->format('Y-m');
            $ruta = $this->option('archivo') ?: storage_path('app/traspasos/biblioumag-'.$mes.'-'.now()->format('Ymd-His').'.json');
            if (! str_starts_with($ruta, '/')) {
                $ruta = base_path($ruta);
            }
            if (file_exists($ruta)) {
                throw new RuntimeException('El archivo ya existe. Usa otra ruta para conservar el respaldo anterior.');
            }
            File::ensureDirectoryExists(dirname($ruta), 0700);
            $archivo = $servicio->exportar($mes);
            $json = json_encode($archivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $temporal = tempnam(dirname($ruta), '.traspaso-');
            if ($temporal === false || ! chmod($temporal, 0600) || file_put_contents($temporal, $json) !== strlen($json)) {
                throw new RuntimeException('No se pudo guardar el respaldo completo. Revisa el espacio y los permisos de la carpeta.');
            }
            // link() publica el archivo completo sin sobrescribir otro creado entretanto.
            if (! link($temporal, $ruta)) {
                throw new RuntimeException('No se pudo publicar el respaldo; comprueba que la ruta no exista.');
            }
            $this->table(['Tabla', 'Registros', 'Dependencias'], collect($archivo['tablas'])->filter()->map(
                fn ($filas, $tabla) => [$tabla, count($filas), count(array_filter($filas, fn ($r) => $r['modo'] === 'referencia'))],
            )->values()->all());
            $this->info("Respaldo de {$mes}: {$ruta}");
            $this->line('Incluye datos personales y contraseñas almacenadas como hash: guarda el archivo en una ubicación privada.');

            return self::SUCCESS;
        } catch (QueryException $e) {
            $this->error('No se pudo exportar. Verifica la conexión y ejecuta las migraciones pendientes.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporal && file_exists($temporal)) {
                unlink($temporal);
            }
        }
    }
}
