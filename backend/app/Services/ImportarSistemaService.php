<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Usuario;
use App\Support\TraspasoEsquema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ImportarSistemaService
{
    private array $ids = [];

    private array $ocupados = [];

    private array $resumen = [];

    private array $mapeos = [];

    private int $temporal = -1;

    public function __construct(private TraspasoEsquema $esquema) {}

    public function importar(array $archivo, bool $simular = false): array
    {
        $this->validar($archivo);
        $this->ids = $this->ocupados = $this->resumen = $this->mapeos = [];
        $this->temporal = -1;
        $nivel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            // Evita otras cargas/importaciones y escrituras mientras se resuelven
            // claves, duplicados y relaciones. Las lecturas del panel siguen funcionando.
            $tablas = array_merge(array_keys(TraspasoEsquema::CLAVES), ['traspaso_registros', 'personal_access_tokens']);
            DB::statement('LOCK TABLE '.implode(', ', $tablas).' IN SHARE ROW EXCLUSIVE MODE');
            foreach (DB::table('traspaso_registros')->where('origen', $archivo['origen'])->get() as $mapeo) {
                $this->mapeos[$mapeo->tabla][$mapeo->origen_id] = $mapeo->destino_id;
            }
            $origenLocal = DB::table('traspaso_instancia')->where('id', 1)->value('uuid');
            foreach (array_keys(TraspasoEsquema::CLAVES) as $tabla) {
                $this->resumen[$tabla] = array_fill_keys(['creados', 'actualizados', 'sin_cambios', 'referencias', 'eliminados'], 0);
                $this->sincronizarHijas($archivo, $tabla);
                $guardar = [];
                foreach ($archivo['tablas'][$tabla] as $registro) {
                    $original = $registro['datos'];
                    $fila = $this->remapear($tabla, $original);
                    $existente = $this->existente($archivo['origen'], $origenLocal, $tabla, $registro, $fila);
                    $id = $existente['id'] ?? null;
                    if ($id !== null && isset($this->ocupados[$tabla][$id])) {
                        throw new RuntimeException("Conflicto: dos registros de {$tabla} apuntan a la misma fila del servidor.");
                    }
                    unset($fila['id']);
                    if ($existente) {
                        if ($registro['modo'] === 'referencia') {
                            // De los recursos antiguos se conserva el catálogo del servidor,
                            // pero su disponibilidad debe acompañar a los movimientos nuevos.
                            $campos = match ($tabla) {
                                'ejemplares' => ['disponible', 'estado_proceso', 'estado_personalizado_id', 'updated_at'],
                                'equipos' => ['disponible', 'deleted_at', 'motivo_baja', 'updated_at'],
                                default => [],
                            };
                            $fila = array_intersect_key($fila, array_flip($campos));
                        }
                        $cambios = array_filter($fila, fn ($valor, $campo) => ! $this->iguales($tabla, $campo, $valor, $existente[$campo]), ARRAY_FILTER_USE_BOTH);
                        if ($cambios) {
                            DB::table($tabla)->where('id', $id)->update($cambios);
                            $this->invalidarSesiones($tabla, $id, $cambios);
                            $this->resumen[$tabla]['actualizados']++;
                        } else {
                            $this->resumen[$tabla][$registro['modo'] === 'referencia' ? 'referencias' : 'sin_cambios']++;
                        }
                    } else {
                        if ($simular) {
                            // nextval() no se revierte en PostgreSQL. Los IDs temporales
                            // permiten probar restricciones sin tocar ninguna secuencia.
                            $id = $this->temporal--;
                            DB::table($tabla)->insert(['id' => $id] + $fila);
                        } elseif ($tabla === 'configuracion_institucional') {
                            $id = 1;
                            DB::table($tabla)->insert(['id' => $id] + $fila);
                        } else {
                            $id = DB::table($tabla)->insertGetId($fila);
                        }
                        $this->resumen[$tabla]['creados']++;
                    }
                    $this->ids[$tabla][$original['id']] = $id;
                    $this->ocupados[$tabla][$id] = true;
                    if (! $simular && ($this->mapeos[$tabla][$original['id']] ?? null) !== $id) {
                        $guardar[] = ['origen' => $archivo['origen'], 'tabla' => $tabla, 'origen_id' => $original['id'], 'destino_id' => $id];
                    }
                }
                foreach (array_chunk($guardar, 1000) as $bloque) {
                    DB::table('traspaso_registros')->upsert($bloque, ['origen', 'tabla', 'origen_id'], ['destino_id']);
                }
            }
            $this->validarCorreos($archivo);
            $this->validarCirculacion();
            if ($simular) {
                DB::rollBack();
            } else {
                // numero_ficha se conserva, por lo que también se adelanta su secuencia.
                DB::statement("SELECT setval('libros_numero_ficha_seq', GREATEST((SELECT last_value FROM libros_numero_ficha_seq), (SELECT COALESCE(MAX(numero_ficha), 1) FROM libros)), true)");
                DB::commit();
            }
        } catch (\Throwable $e) {
            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }
            throw $e;
        }

        return $this->resumen;
    }

    private function validar(array $archivo): void
    {
        if (($archivo['formato'] ?? null) !== 'biblioumag-traspaso' || ($archivo['version'] ?? null) !== 1
            || ! is_string($archivo['origen'] ?? null) || ! Str::isUuid($archivo['origen'])
            || ! is_string($archivo['mes'] ?? null) || ! is_array($archivo['tablas'] ?? null)) {
            throw new InvalidArgumentException('El archivo no es un traspaso válido de BiblioUMAG (versión 1).');
        }
        ExportarSistemaService::mes($archivo['mes']);
        if (($archivo['esquema'] ?? null) !== $this->esquema->firma()
            || ($archivo['zona_horaria'] ?? null) !== config('app.timezone')) {
            throw new InvalidArgumentException('El esquema o la zona horaria no coincide. Actualiza ambos sistemas y ejecuta sus migraciones antes de exportar otra vez.');
        }
        $tablas = array_keys($archivo['tablas']);
        if (array_diff($tablas, array_keys(TraspasoEsquema::CLAVES)) || array_diff(array_keys(TraspasoEsquema::CLAVES), $tablas)) {
            throw new InvalidArgumentException('El archivo contiene tablas desconocidas o le faltan tablas.');
        }
        foreach ($archivo['tablas'] as $tabla => $registros) {
            if (! is_array($registros) || ! array_is_list($registros)) {
                throw new InvalidArgumentException("La lista de {$tabla} no es válida.");
            }
            $vistas = [];
            $columnas = array_keys($this->esquema->columnas($tabla));
            foreach ($registros as $registro) {
                $fila = $registro['datos'] ?? null;
                if (! is_array($fila) || ! in_array($registro['modo'] ?? null, ['reemplazar', 'referencia'], true)
                    || ! is_int($fila['id'] ?? null) || $fila['id'] <= 0 || isset($vistas[$fila['id']])
                    || array_diff(array_keys($fila), $columnas) || array_diff($columnas, array_keys($fila))
                    || count(array_filter($fila, fn ($v) => ! is_scalar($v) && $v !== null)) > 0
                    || ! array_key_exists('horizon', $registro)
                    || (! is_null($registro['horizon']) && ! is_int($registro['horizon']) && ! is_string($registro['horizon']))) {
                    throw new InvalidArgumentException("Hay una fila inválida, incompleta o repetida en {$tabla}.");
                }
                $vistas[$fila['id']] = true;
            }
        }
    }

    private function remapear(string $tabla, array $fila): array
    {
        foreach ($this->esquema->foraneas($tabla) as $fk) {
            $columna = $fk['columns'][0];
            if ($fila[$columna] !== null && $fk['foreign_columns'][0] === 'id') {
                $fila[$columna] = $this->ids[$fk['foreign_table']][$fila[$columna]]
                    ?? throw new RuntimeException("Falta una dependencia de {$tabla}.{$columna} en el archivo.");
            }
        }
        if ($tabla === 'bitacora' && $fila['entidad_id'] !== null) {
            $entidad = TraspasoEsquema::ENTIDADES[$fila['entidad_tipo']] ?? null;
            $id = $this->ids[$entidad][$fila['entidad_id']] ?? null;
            if ($id === null) {
                // Una entidad eliminada no debe enlazar por accidente a un ID ajeno.
                $datos = json_decode($fila['datos'] ?? '{}', true, flags: JSON_THROW_ON_ERROR) ?? [];
                $datos['_referencia_original_traspaso'] = ['tipo' => $fila['entidad_tipo'], 'id' => $fila['entidad_id']];
                $fila['datos'] = json_encode($datos, JSON_THROW_ON_ERROR);
            }
            $fila['entidad_id'] = $id;
        }

        return $fila;
    }

    private function existente(string $origen, ?string $origenLocal, string $tabla, array $registro, array $fila): ?array
    {
        $id = $this->mapeos[$tabla][$fila['id']] ?? null;
        if ($id === null && $origen === $origenLocal) {
            $id = $fila['id'];
        }
        $existente = $id !== null ? DB::table($tabla)->find($id) : null;
        if ($existente) {
            return (array) $existente;
        }
        if (($registro['horizon'] ?? null) !== null && isset(TraspasoEsquema::HORIZON[$tabla])) {
            [$mapa, $local, $externa] = TraspasoEsquema::HORIZON[$tabla];
            if (Schema::hasTable("horizon_etl.{$mapa}")) {
                $id = DB::table("horizon_etl.{$mapa}")->where($externa, $registro['horizon'])->value($local);
                if ($id !== null && $existente = DB::table($tabla)->find($id)) {
                    return (array) $existente;
                }
            }
        }
        if ($tabla === 'usuarios') {
            $query = Usuario::porIdentificador($fila['rut'])->toBase();
        } elseif (in_array($tabla, ['staff', 'usuario_correos'], true)) {
            $query = DB::table($tabla)->whereRaw('lower(email) = ?', [mb_strtolower(trim($fila['email']))]);
        } else {
            $claves = TraspasoEsquema::CLAVES[$tabla];
            // Sin clave natural: solo se adopta una fila completamente idéntica.
            // Las siguientes importaciones usan siempre la correspondencia persistente.
            $campos = $claves ?: array_keys(array_diff_key($fila, ['id' => true]));
            $query = DB::table($tabla);
            foreach ($campos as $campo) {
                $query->where($campo, $fila[$campo]);
            }
            if (! $claves) {
                $query->whereNotIn('id', array_keys($this->ocupados[$tabla] ?? []));
            }
        }
        $resultados = $query->limit(2)->get();
        if ($resultados->count() > 1) {
            throw new RuntimeException("Coincidencia ambigua en {$tabla}: revisa los duplicados del servidor.");
        }
        $existente = $resultados->first();
        if ($existente && $tabla === 'libros' && $registro['horizon'] === null
            && $existente->created_at !== $fila['created_at']) {
            throw new RuntimeException('Colisión de número de ficha: un libro del origen coincide con una ficha de otra procedencia. Resuelve la numeración antes de importar.');
        }

        return $existente ? (array) $existente : null;
    }

    private function sincronizarHijas(array $archivo, string $tabla): void
    {
        $relaciones = [
            'usuario_correos' => ['usuarios', 'usuario_id', ['email']],
            'libro_autor' => ['libros', 'libro_id', ['autor_id']],
            'libro_categoria' => ['libros', 'libro_id', ['categoria_id']],
            'libro_carrera' => ['libros', 'libro_id', ['carrera_id']],
            'reserva_participantes' => ['reservas', 'reserva_id', ['usuario_id']],
        ];
        if (! isset($relaciones[$tabla])) {
            return;
        }
        [$padre, $fk, $claves] = $relaciones[$tabla];
        $completos = [];
        foreach ($archivo['tablas'][$padre] as $registro) {
            if ($registro['modo'] === 'reemplazar') {
                $completos[] = $this->ids[$padre][$registro['datos']['id']];
            }
        }
        $esperados = [];
        foreach ($archivo['tablas'][$tabla] as $registro) {
            $fila = $this->remapear($tabla, $registro['datos']);
            $esperados[$fila[$fk]][] = array_intersect_key($fila, array_flip($claves));
        }
        foreach (array_chunk($completos, 1000) as $bloque) {
            foreach (DB::table($tabla)->whereIn($fk, $bloque)->get() as $fila) {
                $clave = array_intersect_key((array) $fila, array_flip($claves));
                if (! in_array($clave, $esperados[$fila->$fk] ?? [])) {
                    DB::table($tabla)->where('id', $fila->id)->delete();
                    DB::table('traspaso_registros')->where(['tabla' => $tabla, 'destino_id' => $fila->id])->delete();
                    $this->resumen[$tabla]['eliminados']++;
                }
            }
        }
    }

    private function iguales(string $tabla, string $campo, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        $tipo = $this->esquema->columnas($tabla)[$campo]['type_name'];
        if (in_array($tipo, ['json', 'jsonb'], true)) {
            return json_decode($a, true, flags: JSON_THROW_ON_ERROR) == json_decode($b, true, flags: JSON_THROW_ON_ERROR);
        }
        if ($tipo === 'bool') {
            return (bool) $a === (bool) $b;
        }

        return (string) $a === (string) $b;
    }

    private function invalidarSesiones(string $tabla, int $id, array $cambios): void
    {
        if (in_array($tabla, ['usuarios', 'staff'], true)
            && array_intersect(array_keys($cambios), ['password', 'rol', 'activo', 'vigente_hasta', 'email', 'rut', 'deleted_at'])) {
            $tipo = $tabla === 'staff' ? (new Staff)->getMorphClass() : (new Usuario)->getMorphClass();
            DB::table('personal_access_tokens')->where(['tokenable_type' => $tipo, 'tokenable_id' => $id])->delete();
        }
    }

    private function validarCorreos(array $archivo): void
    {
        $esperados = [];
        foreach ($archivo['tablas']['usuarios'] as $registro) {
            $fila = $registro['datos'];
            if ($fila['email'] !== null && $fila['email'] !== '') {
                $esperados[mb_strtolower($fila['email'])][] = $this->ids['usuarios'][$fila['id']];
            }
        }
        foreach ($archivo['tablas']['usuario_correos'] as $registro) {
            $fila = $registro['datos'];
            $esperados[mb_strtolower($fila['email'])][] = $this->ids['usuarios'][$fila['usuario_id']];
        }
        $adicionales = DB::table('usuario_correos')->selectRaw('lower(email) as correo, usuario_id');
        $principales = DB::table('usuarios')->whereNotNull('email')->where('email', '!=', '')
            ->selectRaw('lower(email) as correo, id as usuario_id')->unionAll($adicionales);
        $compartidos = DB::query()->fromSub($principales, 'correos')->selectRaw('correo, json_agg(distinct usuario_id) as duenos')->groupBy('correo')
            ->havingRaw('count(distinct usuario_id) > 1')->get();
        foreach ($compartidos as $compartido) {
            $duenos = json_decode($compartido->duenos, true, flags: JSON_THROW_ON_ERROR);
            $origen = array_values(array_unique($esperados[$compartido->correo] ?? []));
            // Horizon puede traer correos compartidos. Conservar exactamente esa
            // situación es válido; agregarle una persona del servidor no lo es.
            if ($origen && (array_diff($duenos, $origen) || array_diff($origen, $duenos))) {
                throw new RuntimeException('Un correo pertenece a usuarios distintos entre el respaldo y el servidor. Corrige el conflicto antes de importar.');
            }
        }
    }

    private function validarCirculacion(): void
    {
        foreach (['ejemplar_id' => 'ejemplares', 'equipo_id' => 'equipos'] as $fk => $tabla) {
            foreach (array_chunk(array_values($this->ids[$tabla] ?? []), 1000) as $ids) {
                if (DB::table('prestamos')->select($fk)->whereIn($fk, $ids)->where('estado', 'activo')->groupBy($fk)->havingRaw('count(*) > 1')->exists()) {
                    throw new RuntimeException("El traspaso dejaría préstamos activos duplicados para {$tabla}. No se aplicó la importación.");
                }
            }
        }
    }
}
