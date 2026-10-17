<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bitacora as Registro;
use App\Models\Staff;
use App\Support\Bitacora;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Pestaña «Acciones del personal» del Registro absoluto: quién prestó, devolvió,
 * multó, cambió de estado, creó o editó qué y cuándo (ver App\Support\Bitacora).
 * Es de solo lectura: la bitácora no se edita ni se borra desde la aplicación.
 */
class BitacoraController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'modulo' => ['nullable', 'array'],
            'modulo.*' => [Rule::in(array_keys(Bitacora::MODULOS))],
            'staff_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $desde = Carbon::parse($request->query('desde', now()->subDays(30)->toDateString()))->startOfDay();
        $hasta = Carbon::parse($request->query('hasta', now()->toDateString()))->endOfDay();

        $query = Registro::query()->whereBetween('created_at', [$desde, $hasta]);

        if ($modulos = $request->query('modulo')) {
            $query->whereIn('modulo', (array) $modulos);
        }

        if ($staffId = $request->query('staff_id')) {
            $query->where('staff_id', $staffId);
        }

        // Busca en lo que se hizo, a quién afectó y quién lo hizo, sin importar tildes.
        if ($busqueda = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($busqueda) {
                $q->whereSinTildes('descripcion', $busqueda)
                    ->orWhereSinTildes('afectado', $busqueda)
                    ->orWhereSinTildes('actor_nombre', $busqueda);
            });
        }

        // Por páginas: la bitácora crece con cada acción del personal, todos los días.
        $pagina = $query->latest('created_at')->latest('id')->paginate(Paginacion::porPagina($request, 50, 500));

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total' => $pagina->total(),
            'current_page' => $pagina->currentPage(),
            'last_page' => $pagina->lastPage(),
            'per_page' => $pagina->perPage(),
            'from' => $pagina->firstItem(),
            'to' => $pagina->lastItem(),
            'acciones' => collect($pagina->items())->map(fn (Registro $r) => [
                'id' => $r->id,
                'fecha_hora' => $r->created_at?->toIso8601String(),
                'actor' => $r->actor_nombre,
                'actor_tipo' => $r->staff_id ? 'staff' : ($r->usuario_id ? 'usuario' : 'sistema'),
                'modulo' => $r->modulo,
                'accion' => $r->accion,
                'descripcion' => $r->descripcion,
                'afectado' => $r->afectado,
                'datos' => $r->datos,
            ]),
        ]);
    }

    /** Módulos y personas para los filtros de la pantalla. */
    public function opciones()
    {
        return response()->json([
            'modulos' => collect(Bitacora::MODULOS)->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values(),
            // También las cuentas eliminadas: sus acciones siguen en la bitácora.
            'personal' => Staff::withTrashed()->orderBy('nombre')->get(['id', 'nombre', 'activo', 'deleted_at'])
                ->map(fn (Staff $s) => ['id' => $s->id, 'nombre' => $s->nombre, 'activo' => $s->activo, 'eliminada' => $s->trashed()]),
        ]);
    }
}
