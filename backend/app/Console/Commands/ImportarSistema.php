<?php

namespace App\Console\Commands;

use App\Services\ImportarSistemaService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class ImportarSistema extends Command
{
    protected $signature = 'sistema:importar
        {archivo : Ruta del JSON generado por sistema:exportar}
        {--simular : Comprueba y muestra los cambios; revierte todas las escrituras y no consume secuencias}';

    protected $description = 'Crea o actualiza un traspaso sin duplicar registros; con --simular no guarda cambios';

    public function handle(ImportarSistemaService $servicio): int
    {
        try {
            $ruta = $this->argument('archivo');
            if (! str_starts_with($ruta, '/')) {
                $ruta = base_path($ruta);
            }
            if (! is_file($ruta) || ! is_readable($ruta)) {
                throw new RuntimeException('No se puede leer el archivo de traspaso. Revisa la ruta y los permisos.');
            }
            $archivo = json_decode(file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($archivo)) {
                throw new RuntimeException('El contenido del archivo no es un traspaso válido.');
            }
            $resumen = $servicio->importar($archivo, (bool) $this->option('simular'));
            $this->table(['Tabla', 'Crear', 'Actualizar', 'Iguales', 'Referencias', 'Quitar relaciones'], collect($resumen)
                ->filter(fn ($fila) => array_sum($fila) > 0)->map(fn ($fila, $tabla) => [$tabla, ...array_values($fila)])->values()->all());
            $this->info($this->option('simular')
                ? 'Simulación completada. No se guardó ningún cambio ni se consumieron secuencias.'
                : 'Traspaso importado. Los movimientos existentes conservaron su identidad.');

            return self::SUCCESS;
        } catch (QueryException $e) {
            // La excepción SQL incluye bindings (contraseñas/hash y datos personales).
            // Se muestra solo la restricción para poder diagnosticar sin exponerlos.
            preg_match('/constraint "([a-zA-Z0-9_]+)"/', $e->errorInfo[2] ?? '', $coincidencia);
            $this->error('Importación cancelada; no se guardaron cambios. Revisa las migraciones y los conflictos de datos.'
                .(isset($coincidencia[1]) ? ' Restricción: '.$coincidencia[1].'.' : ''));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Importación cancelada; no se guardaron cambios. '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
