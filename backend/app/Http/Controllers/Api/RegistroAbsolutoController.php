<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrada;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RegistroAbsolutoController extends Controller
{
    private const TIPOS = ['prestamo', 'reserva_sala', 'reserva_libro', 'entrada'];

    /**
     * Registro unificado de "operaciones que alguien solicitó": préstamos (libros y
     * equipos), reservas de sala, reservas de libro y entradas. Cada tabla tiene su
     * propia forma — acá se normaliza cada una a una fila común (fecha_hora, usuario,
     * detalle, estado, atendido_por) para verlas juntas, ordenadas por fecha y
     * filtrables por rango. Antes de esto, préstamos era el único con listado global;
     * reservas de sala y entradas solo se podían ver un día a la vez, y reservas de
     * libro no tenía ningún listado global (ver LibroController/EjemplarController para
     * el historial de estado de ejemplares, que es un registro aparte y no entra acá).
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
        $busqueda = $request->query('q');

        $filas = collect();

        if (in_array('prestamo', $tipos, true)) {
            $filas = $filas->concat($this->filasPrestamos($desde, $hasta, $busqueda));
        }
        if (in_array('reserva_sala', $tipos, true)) {
            $filas = $filas->concat($this->filasReservasSala($desde, $hasta, $busqueda));
        }
        if (in_array('reserva_libro', $tipos, true)) {
            $filas = $filas->concat($this->filasReservasLibro($desde, $hasta, $busqueda));
        }
        if (in_array('entrada', $tipos, true)) {
            $filas = $filas->concat($this->filasEntradas($desde, $hasta, $busqueda));
        }

        $filas = $filas->sortByDesc('fecha_hora')->values();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total' => $filas->count(),
            'operaciones' => $filas,
        ]);
    }

    private function filasPrestamos(Carbon $desde, Carbon $hasta, ?string $busqueda): Collection
    {
        $query = Prestamo::with(['usuario:id,nombre,apellido,rut', 'prestadoPorStaff:id,nombre'])
            ->whereBetween('fecha_prestamo', [$desde, $hasta]);

        if ($busqueda) {
            $query->whereHas('usuario', fn ($u) => $u->where('nombre', 'ilike', "%{$busqueda}%")
                ->orWhere('apellido', 'ilike', "%{$busqueda}%")
                ->orWhere('rut', 'ilike', "%{$busqueda}%"));
        }

        return $query->latest('fecha_prestamo')->limit(500)->get()->map(fn ($p) => [
            'tipo' => $p->tipo_item === 'libro' ? 'prestamo_libro' : 'prestamo_equipo',
            'fecha_hora' => optional($p->fecha_prestamo)->toIso8601String(),
            'usuario_nombre' => $p->usuario ? trim($p->usuario->nombre.' '.$p->usuario->apellido) : null,
            'usuario_rut' => $p->usuario->rut ?? null,
            'detalle' => $p->libro_titulo,
            'estado' => $p->estado,
            'atendido_por' => $p->prestadoPorStaff->nombre ?? $p->prestado_por,
            'origen_id' => $p->id,
        ]);
    }

    private function filasReservasSala(Carbon $desde, Carbon $hasta, ?string $busqueda): Collection
    {
        $query = Reserva::with(['sala:id,nombre', 'usuario:id,nombre,apellido,rut', 'prestadoPorStaff:id,nombre'])
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()]);

        if ($busqueda) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('rut_usuario', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('usuario', fn ($u) => $u->where('nombre', 'ilike', "%{$busqueda}%")
                        ->orWhere('apellido', 'ilike', "%{$busqueda}%"));
            });
        }

        return $query->latest('fecha')->limit(500)->get()->map(fn ($r) => [
            'tipo' => 'reserva_sala',
            // hora_inicio/hora_fin son horas enteras (bloques de 2h) — no hay minutos que preservar.
            'fecha_hora' => Carbon::parse($r->fecha->toDateString())->setTime((int) $r->hora_inicio, 0)->toIso8601String(),
            'usuario_nombre' => $r->usuario ? trim($r->usuario->nombre.' '.$r->usuario->apellido) : null,
            'usuario_rut' => $r->rut_usuario,
            'detalle' => ($r->sala->nombre ?? 'Sala eliminada')." ({$r->hora_inicio}:00–{$r->hora_fin}:00)",
            'estado' => $r->estado,
            // prestadoPorStaff es la fuente de verdad (FK real, desde 2026-08-17); el
            // fallback a prestado_por (texto) cubre reservas anteriores a esa migración.
            'atendido_por' => $r->prestadoPorStaff->nombre ?? $r->prestado_por,
            'origen_id' => $r->id,
        ]);
    }

    private function filasReservasLibro(Carbon $desde, Carbon $hasta, ?string $busqueda): Collection
    {
        $query = ReservaLibro::with(['usuario:id,nombre,apellido,rut', 'libro:id,titulo', 'registradoPorStaff:id,nombre'])
            ->whereBetween('fecha_reserva', [$desde->toDateString(), $hasta->toDateString()]);

        if ($busqueda) {
            $query->whereHas('usuario', fn ($u) => $u->where('nombre', 'ilike', "%{$busqueda}%")
                ->orWhere('apellido', 'ilike', "%{$busqueda}%")
                ->orWhere('rut', 'ilike', "%{$busqueda}%"));
        }

        return $query->latest('fecha_reserva')->limit(500)->get()->map(fn ($r) => [
            'tipo' => 'reserva_libro',
            'fecha_hora' => Carbon::parse($r->fecha_reserva->toDateString())->toIso8601String(),
            'usuario_nombre' => $r->usuario ? trim($r->usuario->nombre.' '.$r->usuario->apellido) : null,
            'usuario_rut' => $r->usuario->rut ?? null,
            'detalle' => $r->libro->titulo ?? 'Libro eliminado',
            'estado' => $r->estado,
            // null cuando la reserva la creó el propio usuario desde el portal de
            // autoservicio — no hay staff detrás, es un dato real, no uno faltante.
            'atendido_por' => $r->registradoPorStaff->nombre ?? null,
            'origen_id' => $r->id,
        ]);
    }

    private function filasEntradas(Carbon $desde, Carbon $hasta, ?string $busqueda): Collection
    {
        $query = Entrada::with(['usuario:id,nombre,apellido,rut', 'registradoPorStaff:id,nombre'])
            ->whereBetween('fecha_hora_entrada', [$desde, $hasta]);

        if ($busqueda) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('rut_externo', 'ilike', "%{$busqueda}%")
                    ->orWhere('nombre_externo', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('usuario', fn ($u) => $u->where('nombre', 'ilike', "%{$busqueda}%")
                        ->orWhere('apellido', 'ilike', "%{$busqueda}%")
                        ->orWhere('rut', 'ilike', "%{$busqueda}%"));
            });
        }

        return $query->latest('fecha_hora_entrada')->limit(500)->get()->map(fn ($e) => [
            'tipo' => 'entrada',
            'fecha_hora' => optional($e->fecha_hora_entrada)->toIso8601String(),
            'usuario_nombre' => $e->usuario ? trim($e->usuario->nombre.' '.$e->usuario->apellido) : $e->nombre_externo,
            'usuario_rut' => $e->usuario->rut ?? $e->rut_externo,
            'detalle' => $e->es_convenio ? 'Entrada (Convenio)' : ($e->es_visita ? 'Entrada (Visita)' : 'Entrada'),
            // Sin estado real: fecha_hora_salida se estampa igual a fecha_hora_entrada
            // apenas se crea (ver EntradaController::store) — no hay un ciclo abierto/cerrado.
            'estado' => null,
            // null cuando la registró el propio usuario (portal/QR) — no hay staff detrás.
            'atendido_por' => $e->registradoPorStaff->nombre ?? null,
            'origen_id' => $e->id,
        ]);
    }
}
