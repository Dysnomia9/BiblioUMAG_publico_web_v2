<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Paginacion;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RegistroAbsolutoController extends Controller
{
    private const TIPOS = ['prestamo', 'reserva_sala', 'reserva_libro', 'entrada'];

    /**
     * Registro unificado de "operaciones que alguien solicitó": préstamos (libros y
     * equipos), reservas de sala, reservas de libro y entradas. Cada tabla tiene su
     * propia forma — acá se normaliza cada una a una fila común (fecha_hora, usuario,
     * detalle, estado, atendido_por) con un UNION ALL, y la base ordena por fecha y
     * entrega solo la página pedida: con años de historial no se trae todo de una vez.
     *
     * El SQL usa solo lo que entienden PostgreSQL y MySQL (CAST ... AS CHAR, CONCAT,
     * CONCAT_WS, CASE, COALESCE): la fecha va como texto 'AAAA-MM-DD HH:MM:SS', que se
     * ordena igual que la fecha.
     */
    public function index(Request $request)
    {
        $desde = $request->query('desde')
            ? Carbon::parse($request->query('desde'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $hasta = $request->query('hasta')
            ? Carbon::parse($request->query('hasta'))->endOfDay()
            : now()->endOfDay();

        $tiposSolicitados = $request->query('tipo');
        $tipos = $tiposSolicitados ? array_intersect((array) $tiposSolicitados, self::TIPOS) : self::TIPOS;
        $busqueda = trim((string) $request->query('q'));

        $partes = array_values(array_filter([
            in_array('prestamo', $tipos, true) ? $this->prestamos($desde, $hasta, $busqueda) : null,
            in_array('reserva_sala', $tipos, true) ? $this->reservasSala($desde, $hasta, $busqueda) : null,
            in_array('reserva_libro', $tipos, true) ? $this->reservasLibro($desde, $hasta, $busqueda) : null,
            in_array('entrada', $tipos, true) ? $this->entradas($desde, $hasta, $busqueda) : null,
        ]));

        $base = ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];

        if (! $partes) {
            return response()->json($base + ['total' => 0, 'operaciones' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 0, 'from' => null, 'to' => null]);
        }

        $union = array_shift($partes);
        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }

        $pagina = DB::query()->fromSub($union, 'operaciones')
            ->orderByDesc('fecha_hora')
            ->orderBy('tipo')
            ->orderByDesc('origen_id')
            ->paginate(Paginacion::porPagina($request, 50, 500));

        $operaciones = collect($pagina->items())->map(fn ($fila) => [
            'tipo' => $fila->tipo,
            'fecha_hora' => $fila->fecha_hora ? Carbon::parse($fila->fecha_hora)->toIso8601String() : null,
            'usuario_nombre' => $fila->usuario_nombre,
            'usuario_rut' => $fila->usuario_rut,
            'detalle' => $fila->detalle,
            'estado' => $fila->estado,
            'atendido_por' => $fila->atendido_por,
            'origen_id' => (int) $fila->origen_id,
        ]);

        return response()->json($base + [
            'total' => $pagina->total(),
            'operaciones' => $operaciones,
            'current_page' => $pagina->currentPage(),
            'last_page' => $pagina->lastPage(),
            'per_page' => $pagina->perPage(),
            'from' => $pagina->firstItem(),
            'to' => $pagina->lastItem(),
        ]);
    }

    /** Nombre y apellido del usuario (u), o null si no hay usuario. */
    private const NOMBRE_USUARIO = "NULLIF(CONCAT_WS(' ', u.nombre, u.apellido), '')";

    /** Persona por nombre (sin tildes) o RUT: el filtro de búsqueda de cada parte. */
    private function buscarUsuario(Builder $query, string $busqueda, string $columnaRut = 'u.rut'): void
    {
        $query->where(fn ($q) => $q->whereCadaPalabra("(u.nombre || ' ' || u.apellido)", $busqueda)
            ->orWhereLike($columnaRut, "%{$busqueda}%"));
    }

    private function prestamos(Carbon $desde, Carbon $hasta, string $busqueda): Builder
    {
        $query = DB::table('prestamos as p')
            ->leftJoin('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->leftJoin('staff as s', 's.id', '=', 'p.prestado_por_staff_id')
            ->whereBetween('p.fecha_prestamo', [$desde, $hasta])
            ->selectRaw("CASE WHEN p.tipo_item = 'libro' THEN 'prestamo_libro' ELSE 'prestamo_equipo' END AS tipo")
            ->selectRaw('CAST(p.fecha_prestamo AS CHAR(19)) AS fecha_hora')
            ->selectRaw(self::NOMBRE_USUARIO.' AS usuario_nombre')
            ->selectRaw('u.rut AS usuario_rut')
            ->selectRaw('p.libro_titulo AS detalle')
            ->selectRaw('p.estado AS estado')
            ->selectRaw('COALESCE(s.nombre, p.prestado_por) AS atendido_por')
            ->selectRaw('p.id AS origen_id');

        if ($busqueda !== '') {
            $this->buscarUsuario($query, $busqueda);
        }

        return $query;
    }

    /** «13-11-2026» a partir de una fecha (o expresión de fecha) de la base. */
    private static function ddmmaaaa(string $fecha): string
    {
        return "CONCAT(SUBSTRING(CAST({$fecha} AS CHAR(10)), 9, 2), '-', SUBSTRING(CAST({$fecha} AS CHAR(10)), 6, 2), '-', SUBSTRING(CAST({$fecha} AS CHAR(10)), 1, 4))";
    }

    /** «17:00–19:00» a partir de las horas de inicio y de término. */
    private static function tramo(string $inicio, string $fin): string
    {
        return "CONCAT(SUBSTRING(CAST({$inicio} AS CHAR(8)), 1, 5), '–', SUBSTRING(CAST({$fin} AS CHAR(8)), 1, 5))";
    }

    /** Las reservas de sala que se hicieron entre esas fechas, con quién y la sala. */
    private function baseReservasSala(Carbon $desde, Carbon $hasta, string $busqueda): Builder
    {
        $query = DB::table('reservas as r')
            ->leftJoin('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->leftJoin('salas as sa', 'sa.id', '=', 'r.sala_id')
            // La hora de la fila es cuándo se hizo la reserva, no a qué hora es la sala: una
            // reserva para las 17:00 hecha (o cancelada) a las 14:00 no puede aparecer arriba
            // de lo que pasó a las 15:00. El día y el tramo reservados van en el detalle.
            ->where(fn ($q) => $q->whereBetween('r.created_at', [$desde, $hasta])
                ->orWhere(fn ($q) => $q->whereNull('r.created_at')->whereBetween('r.fecha', [$desde->toDateString(), $hasta->toDateString()])));

        if ($busqueda !== '') {
            // También la persona a cargo de una reserva por nombre (salas de la administración, sin RUT).
            $query->where(fn ($q) => $q->where(fn ($u) => $this->buscarUsuario($u, $busqueda, 'r.rut_usuario'))
                ->orWhere(fn ($a) => $a->whereCadaPalabra('r.nombre_actividad', $busqueda)));
        }

        return $query;
    }

    /**
     * Las reservas de sala de a una, y cada serie (la misma reserva semana a semana,
     * SalaController::repetirReserva) en una sola fila: se pidió una vez, no una por fecha.
     */
    private function reservasSala(Carbon $desde, Carbon $hasta, string $busqueda): Builder
    {
        // La persona con RUT o, si se reservó por nombre, la persona a cargo / grupo.
        $quien = 'COALESCE('.self::NOMBRE_USUARIO.', r.nombre_actividad)';

        $sueltas = $this->baseReservasSala($desde, $hasta, $busqueda)
            ->whereNull('r.serie')
            // prestado_por_staff_id es la fuente de verdad (FK real, desde 2026-08-17); el
            // texto prestado_por cubre reservas anteriores a esa migración.
            ->leftJoin('staff as s', 's.id', '=', 'r.prestado_por_staff_id')
            ->selectRaw("'reserva_sala' AS tipo")
            ->selectRaw("COALESCE(CAST(r.created_at AS CHAR(19)), CONCAT(CAST(r.fecha AS CHAR(10)), ' ', CAST(r.hora_inicio AS CHAR(8)))) AS fecha_hora")
            ->selectRaw("{$quien} AS usuario_nombre")
            ->selectRaw('r.rut_usuario AS usuario_rut')
            // «Logia 02 (13-11-2026, 17:00–19:00)»: el día reservado, que puede no ser el de la fila.
            ->selectRaw("CONCAT(COALESCE(sa.nombre, 'Sala eliminada'), ' (', ".self::ddmmaaaa('r.fecha').", ', ', ".self::tramo('r.hora_inicio', 'r.hora_fin').", ')') AS detalle")
            ->selectRaw('r.estado AS estado')
            ->selectRaw('COALESCE(s.nombre, r.prestado_por) AS atendido_por')
            ->selectRaw('r.id AS origen_id');

        // «Seminario 1 · serie de 5 fechas, del 23-11-2026 al 21-12-2026, 08:00–11:10 (1 cancelada)».
        // Si una fecha se movió a otra sala u horario, la fila lo dice en vez de mostrar solo uno.
        // La llave se entrega fecha a fecha: quién la entregó se ve en el Listado de reservas.
        $activas = "SUM(CASE WHEN r.estado = 'activa' THEN 1 ELSE 0 END)";
        $canceladas = "SUM(CASE WHEN r.estado = 'cancelada' THEN 1 ELSE 0 END)";
        $series = $this->baseReservasSala($desde, $hasta, $busqueda)
            ->whereNotNull('r.serie')
            ->groupBy('r.serie')
            ->selectRaw("'reserva_sala' AS tipo")
            ->selectRaw('CAST(MIN(r.created_at) AS CHAR(19)) AS fecha_hora')
            ->selectRaw("MIN({$quien}) AS usuario_nombre")
            ->selectRaw('MIN(r.rut_usuario) AS usuario_rut')
            ->selectRaw("CONCAT("
                ."CASE WHEN COUNT(DISTINCT r.sala_id) > 1 THEN 'Varias salas' ELSE COALESCE(MIN(sa.nombre), 'Sala eliminada') END, "
                ."' · serie de ', TRIM(CAST(COUNT(*) AS CHAR(10))), ' fechas, del ', ".self::ddmmaaaa('MIN(r.fecha)').", ' al ', ".self::ddmmaaaa('MAX(r.fecha)').", ', ', "
                ."CASE WHEN COUNT(DISTINCT CONCAT(CAST(r.hora_inicio AS CHAR(8)), CAST(r.hora_fin AS CHAR(8)))) > 1 THEN 'horario variable' ELSE ".self::tramo('MIN(r.hora_inicio)', 'MIN(r.hora_fin)').' END, '
                ."CASE WHEN {$canceladas} = 0 THEN '' WHEN {$canceladas} = 1 THEN ' (1 cancelada)' ELSE CONCAT(' (', TRIM(CAST({$canceladas} AS CHAR(10))), ' canceladas)') END"
                .') AS detalle')
            // Activa mientras le quede alguna fecha por usar; si no, cancelada si se cancelaron todas.
            ->selectRaw("CASE WHEN {$activas} > 0 THEN 'activa' WHEN {$canceladas} = COUNT(*) THEN 'cancelada' ELSE 'finalizada' END AS estado")
            ->selectRaw('CAST(NULL AS CHAR(100)) AS atendido_por')
            ->selectRaw('MIN(r.id) AS origen_id');

        // En una subconsulta, para que se una al resto del registro como cualquier otra parte.
        return DB::query()->fromSub($sueltas->unionAll($series), 'reservas_sala');
    }

    private function reservasLibro(Carbon $desde, Carbon $hasta, string $busqueda): Builder
    {
        $query = DB::table('reservas_libro as rl')
            ->leftJoin('usuarios as u', 'u.id', '=', 'rl.usuario_id')
            ->leftJoin('libros as l', 'l.id', '=', 'rl.libro_id')
            // Sin personal cuando la reserva la hizo el propio usuario desde el portal.
            ->leftJoin('staff as s', 's.id', '=', 'rl.registrado_por_staff_id')
            ->whereBetween('rl.fecha_reserva', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw("'reserva_libro' AS tipo")
            ->selectRaw("CONCAT(CAST(rl.fecha_reserva AS CHAR(10)), ' 00:00:00') AS fecha_hora")
            ->selectRaw(self::NOMBRE_USUARIO.' AS usuario_nombre')
            ->selectRaw('u.rut AS usuario_rut')
            ->selectRaw("COALESCE(l.titulo, 'Libro eliminado') AS detalle")
            ->selectRaw('rl.estado AS estado')
            ->selectRaw('s.nombre AS atendido_por')
            ->selectRaw('rl.id AS origen_id');

        if ($busqueda !== '') {
            $this->buscarUsuario($query, $busqueda);
        }

        return $query;
    }

    private function entradas(Carbon $desde, Carbon $hasta, string $busqueda): Builder
    {
        $query = DB::table('entradas as e')
            ->leftJoin('usuarios as u', 'u.id', '=', 'e.usuario_id')
            // Sin personal cuando la marcó el propio usuario (portal/QR).
            ->leftJoin('staff as s', 's.id', '=', 'e.registrado_por_staff_id')
            ->whereBetween('e.fecha_hora_entrada', [$desde, $hasta])
            ->selectRaw("'entrada' AS tipo")
            ->selectRaw('CAST(e.fecha_hora_entrada AS CHAR(19)) AS fecha_hora')
            ->selectRaw('COALESCE('.self::NOMBRE_USUARIO.', e.nombre_externo) AS usuario_nombre')
            ->selectRaw('COALESCE(u.rut, e.rut_externo) AS usuario_rut')
            ->selectRaw("CASE WHEN e.es_convenio = TRUE THEN 'Entrada (Convenio)' WHEN e.es_visita = TRUE THEN 'Entrada (Visita)' WHEN e.via = 'sala' THEN 'Entrada (al llegar a una sala reservada)' ELSE 'Entrada' END AS detalle")
            // Sin estado real: la salida se estampa junto con la entrada (ver EntradaController::store).
            ->selectRaw('CAST(NULL AS CHAR(20)) AS estado')
            ->selectRaw('s.nombre AS atendido_por')
            ->selectRaw('e.id AS origen_id');

        if ($busqueda !== '') {
            $query->where(fn ($q) => $q->whereLike('e.rut_externo', "%{$busqueda}%")
                ->orWhereCadaPalabra('e.nombre_externo', $busqueda)
                ->orWhere(fn ($u) => $this->buscarUsuario($u, $busqueda)));
        }

        return $query;
    }
}
