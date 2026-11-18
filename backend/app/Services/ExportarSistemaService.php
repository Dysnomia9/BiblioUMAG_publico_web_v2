<?php

namespace App\Services;

use App\Support\TraspasoEsquema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExportarSistemaService
{
    public function __construct(private TraspasoEsquema $esquema) {}

    public static function mes(?string $mes): CarbonImmutable
    {
        $mes ??= now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $mes)) {
            throw new InvalidArgumentException('El mes debe tener el formato AAAA-MM, por ejemplo 2026-11.');
        }

        return CarbonImmutable::createFromFormat('!Y-m', $mes, config('app.timezone'));
    }

    public function exportar(?string $mes = null): array
    {
        $inicio = self::mes($mes);

        return DB::transaction(function () use ($inicio) {
            // Una sola fotografía coherente, aunque alguien siga trabajando durante la exportación.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::table('traspaso_instancia')->insertOrIgnore(['id' => 1, 'uuid' => (string) Str::uuid()]);
            $tablas = array_fill_keys(array_keys(TraspasoEsquema::CLAVES), []);
            foreach ($tablas as $tabla => $_) {
                if (in_array($tabla, TraspasoEsquema::COMPLETAS, true)) {
                    $query = DB::table($tabla);
                } elseif (in_array($tabla, TraspasoEsquema::MOVIMIENTOS, true)
                    || in_array($tabla, ['libros', 'ejemplares', 'equipos', 'fasciculos', 'fichas_interbibliotecarias', 'prestamos_interbibliotecarios'], true)) {
                    $query = DB::table($tabla)->where(function (Builder $q) use ($tabla, $inicio) {
                        $q->where(fn ($fecha) => $fecha->where('created_at', '>=', $inicio)->where('created_at', '<', $inicio->addMonth()));
                        // El historial es de solo anexar: una normalización de fechas
                        // no convierte sus filas antiguas en movimientos del mes.
                        if (in_array($tabla, TraspasoEsquema::MOVIMIENTOS, true) && $tabla !== 'ejemplar_estado_historial'
                            && isset($this->esquema->columnas($tabla)['updated_at'])) {
                            $q->orWhere(fn ($fecha) => $fecha->where('updated_at', '>=', $inicio)->where('updated_at', '<', $inicio->addMonth()));
                        }
                    });
                    if (isset(TraspasoEsquema::HORIZON[$tabla])) {
                        [$mapa, $local] = TraspasoEsquema::HORIZON[$tabla];
                        if (Schema::hasTable("horizon_etl.{$mapa}")) {
                            $query->whereNotIn('id', DB::table("horizon_etl.{$mapa}")->select($local));
                        }
                    }
                    if ($tabla === 'multas') {
                        $query->where('origen', '!=', 'importada');
                    }
                    if ($tabla === 'fasciculos') {
                        $query->whereNull('referencia_externa');
                    }
                    if (in_array($tabla, ['libros', 'ejemplares'], true)) {
                        $query->whereNull('fecha_ingreso_horizon');
                    }
                } else {
                    continue;
                }
                $this->agregar($tablas, $tabla, $query, 'reemplazar');
            }

            // Cierre transitivo de las relaciones: se incluyen solo las dependencias
            // necesarias, nunca todos los movimientos históricos de una persona/libro.
            $visitadas = [];
            do {
                $antes = array_sum(array_map('count', $tablas));
                foreach ($tablas as $tabla => $registros) {
                    $nuevas = array_diff_key($registros, $visitadas[$tabla] ?? []);
                    if (! $nuevas) {
                        continue;
                    }
                    $visitadas[$tabla] = $registros;
                    foreach ($this->esquema->foraneas($tabla) as $fk) {
                        $ids = array_unique(array_filter(array_column(array_column($nuevas, 'datos'), $fk['columns'][0]), fn ($v) => $v !== null));
                        foreach (array_chunk($ids, 1000) as $bloque) {
                            $this->agregar($tablas, $fk['foreign_table'], DB::table($fk['foreign_table'])->whereIn($fk['foreign_columns'][0], $bloque), 'referencia');
                        }
                    }
                    if ($tabla === 'bitacora') {
                        foreach ($nuevas as $registro) {
                            $fila = $registro['datos'];
                            $entidad = TraspasoEsquema::ENTIDADES[$fila['entidad_tipo']] ?? null;
                            if ($entidad && $fila['entidad_id'] !== null) {
                                $this->agregar($tablas, $entidad, DB::table($entidad)->where('id', $fila['entidad_id']), 'referencia');
                            }
                        }
                    }
                }
                foreach (TraspasoEsquema::HIJAS as [$padre, $hija, $fk]) {
                    $registros = $tablas[$padre];
                    // Las fichas antiguas no arrastran todas sus copias ni sus fascículos.
                    if (in_array($hija, ['ejemplares', 'fasciculos', 'fichas_interbibliotecarias'], true)) {
                        $registros = array_filter($registros, fn ($r) => $r['modo'] === 'reemplazar');
                    }
                    foreach (array_chunk(array_keys($registros), 1000) as $ids) {
                        $this->agregar($tablas, $hija, DB::table($hija)->whereIn($fk, $ids), 'reemplazar');
                    }
                }
            } while ($antes !== array_sum(array_map('count', $tablas)));

            $horizon = $this->mapasHorizon($tablas);
            foreach ($tablas as $tabla => &$registros) {
                ksort($registros);
                foreach ($registros as $id => &$registro) {
                    $registro['horizon'] = $horizon[$tabla][$id] ?? null;
                }
                unset($registro);
                $registros = array_values($registros);
            }
            unset($registros);

            return [
                'formato' => 'biblioumag-traspaso', 'version' => 1,
                'origen' => DB::table('traspaso_instancia')->where('id', 1)->value('uuid'),
                'mes' => $inicio->format('Y-m'), 'generado_en' => now()->toIso8601String(),
                'zona_horaria' => config('app.timezone'), 'esquema' => $this->esquema->firma(),
                'tablas' => $tablas,
            ];
        });
    }

    private function agregar(array &$tablas, string $tabla, Builder $query, string $modo): void
    {
        foreach ($query->orderBy('id')->get(array_keys($this->esquema->columnas($tabla))) as $objeto) {
            $fila = (array) $objeto;
            if (! isset($tablas[$tabla][$fila['id']]) || $modo === 'reemplazar') {
                $tablas[$tabla][$fila['id']] = ['modo' => $modo, 'datos' => $fila];
            }
        }
    }

    private function mapasHorizon(array $tablas): array
    {
        $mapas = [];
        foreach (TraspasoEsquema::HORIZON as $tabla => [$mapa, $local, $externa]) {
            if (Schema::hasTable("horizon_etl.{$mapa}")) {
                foreach (array_chunk(array_keys($tablas[$tabla]), 1000) as $ids) {
                    foreach (DB::table("horizon_etl.{$mapa}")->whereIn($local, $ids)->pluck($externa, $local) as $id => $valor) {
                        $mapas[$tabla][$id] = $valor;
                    }
                }
            }
        }

        return $mapas;
    }
}
