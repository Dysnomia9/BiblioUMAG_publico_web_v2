<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipo;
use App\Support\Bitacora;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EquipoController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Equipo::query();

        if ($request->has('disponible')) {
            $query->where('disponible', filter_var($request->query('disponible'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($busqueda = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('codigo_inventario', 'ilike', "%{$busqueda}%")
                ->orWhere('codigo_barras', 'ilike', "%{$busqueda}%"));
        }

        // Los contadores por categoría respetan el estado y la búsqueda, antes de
        // elegir una categoría. El inventario pide páginas; Préstamos conserva su
        // lista de recursos disponibles sin cambiar el formato de su respuesta.
        $paginado = $request->has('page') || $request->has('por_pagina');
        $porCategoria = $paginado
            ? (clone $query)->selectRaw('tipo, count(*) as cantidad')->groupBy('tipo')->pluck('cantidad', 'tipo')
            : null;

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        $query->orderBy('codigo_inventario')->orderBy('id');
        if (! $paginado) {
            return response()->json($query->get());
        }

        $pagina = $query->paginate(Paginacion::porPagina($request, 25, 100));
        $resumen = Equipo::selectRaw('count(*) as total, count(*) filter (where activo) as activos, count(*) filter (where not activo) as bajas')->first();

        return response()->json([
            ...$pagina->toArray(),
            'resumen' => ['total' => (int) $resumen->total, 'activos' => (int) $resumen->activos, 'bajas' => (int) $resumen->bajas],
            'por_categoria' => (object) $porCategoria->map(fn ($cantidad) => (int) $cantidad)->all(),
        ]);
    }

    /** Busca un equipo por su código de barras (escaneo en préstamo). */
    public function buscarPorCodigo(string $codigo)
    {
        $equipo = Equipo::porCodigo($codigo)->first();

        if (! $equipo) {
            return response()->json(['message' => 'Código de barras no encontrado en el sistema'], 404);
        }

        // Con su categoría, para decir qué es (notebook, bicicleta…) cuando lo escanean en otra pantalla.
        return response()->json($equipo->load('categoria:id,clave,nombre,nombre_plural'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::reglas(), [
            'tipo.exists' => 'Esa categoría de recurso no existe o está desactivada.',
        ]);

        return response()->json($this->registrar($data), 201);
    }

    /**
     * Registra varios recursos de una vez (la grilla de Recursos de apoyo). Entran todos o
     * ninguno: si una fila trae un código repetido, no queda la mitad cargada.
     */
    public function storeLote(Request $request)
    {
        $reglas = self::reglas();
        $data = $request->validate([
            'recursos' => ['required', 'array', 'min:1', 'max:50'],
            'recursos.*.codigo_inventario' => [...$reglas['codigo_inventario'], 'distinct'],
            'recursos.*.codigo_barras' => [...$reglas['codigo_barras'], 'distinct'],
            'recursos.*.tipo' => $reglas['tipo'],
        ], [
            'recursos.required' => 'Agrega al menos un recurso.',
            'recursos.max' => 'Se pueden registrar hasta 50 recursos de una vez.',
            'recursos.*.codigo_inventario.required' => 'Fila :position: falta el nombre.',
            'recursos.*.codigo_inventario.unique' => 'Fila :position: ya hay un recurso con ese nombre.',
            'recursos.*.codigo_inventario.distinct' => 'Fila :position: ese nombre está repetido en la lista.',
            'recursos.*.codigo_barras.required' => 'Fila :position: falta el código de barras.',
            'recursos.*.codigo_barras.unique' => 'Fila :position: ese código de barras ya es de otro recurso o de una copia de un libro.',
            'recursos.*.codigo_barras.distinct' => 'Fila :position: ese código de barras está repetido en la lista.',
            'recursos.*.tipo.required' => 'Fila :position: elige la categoría.',
            'recursos.*.tipo.exists' => 'Fila :position: esa categoría no existe o está desactivada.',
        ]);

        $equipos = DB::transaction(fn () => array_map(fn (array $recurso) => $this->registrar($recurso), $data['recursos']));

        return response()->json($equipos, 201);
    }

    private static function reglas(): array
    {
        return [
            'codigo_inventario' => ['required', 'string', 'max:255', 'unique:equipos,codigo_inventario'],
            // Ni de otro recurso ni de una copia de un libro (su código o el de lomo): al escanear, sale uno solo
            'codigo_barras' => ['required', 'string', 'max:255', 'unique:equipos,codigo_barras', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo'],
            // La categoría de recurso de apoyo (Administración → Catálogos), solo si está activa.
            'tipo' => ['required', Rule::exists('categorias_recurso', 'clave')->where('activo', true)],
        ];
    }

    private function registrar(array $data): Equipo
    {
        $equipo = Equipo::create($data + ['disponible' => true, 'activo' => true]);

        $categoria = $equipo->categoria?->nombre ?? $equipo->tipo;
        Bitacora::registrar('equipos.agregar', "Agregó el recurso {$equipo->codigo_inventario} ({$categoria}, código {$equipo->codigo_barras})", entidad: $equipo);

        return $equipo;
    }

    public function cambiarActivo(Request $request, Equipo $equipo)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            // Dar de baja pide el motivo: se ve en el inventario y queda en la bitácora.
            'motivo' => [Rule::requiredIf(! $request->boolean('activo')), 'nullable', 'string', 'max:500'],
        ], [
            'motivo.required' => 'Indica el motivo de la baja: queda registrado en el inventario y en la bitácora.',
        ]);

        $activo = (bool) $data['activo'];

        return DB::transaction(function () use ($equipo, $data, $activo) {
            $equipo = Equipo::whereKey($equipo->id)->lockForUpdate()->firstOrFail();

            // No se puede dar de baja un recurso actualmente prestado (mismo criterio que
            // LibroController::cambiarEstado con 'de_baja') — primero debe devolverse.
            if (! $activo && ! $equipo->disponible) {
                return response()->json(['message' => 'No se puede dar de baja un recurso que está prestado: primero tiene que devolverse.'], 409);
            }

            if ($equipo->activo !== $activo) {
                // Al reactivarlo se limpia el motivo: el de cada baja sigue en la bitácora.
                $equipo->update($activo
                    ? ['activo' => true, 'motivo_baja' => null, 'dado_de_baja_el' => null]
                    : ['activo' => false, 'motivo_baja' => $data['motivo'], 'dado_de_baja_el' => now()]);

                Bitacora::registrar(
                    $activo ? 'equipos.reactivar' : 'equipos.dar_de_baja',
                    $activo
                        ? "Reactivó el recurso {$equipo->codigo_inventario}"
                        : "Dio de baja el recurso {$equipo->codigo_inventario}. Motivo: {$data['motivo']}",
                    entidad: $equipo,
                    datos: $activo ? [] : ['motivo' => $data['motivo']],
                );
            }

            return response()->json($equipo);
        });
    }

    /** Oculta del inventario un recurso de baja, conservando sus préstamos y su bitácora. */
    public function eliminar(Request $request, Equipo $equipo)
    {
        $request->validate([
            'confirmacion' => ['required', 'string', 'max:50', function ($campo, $valor, $falla) {
                if (mb_strtolower(trim($valor)) !== 'confirmar') {
                    $falla('Escribe «confirmar» para eliminar el recurso.');
                }
            }],
        ], ['confirmacion.required' => 'Escribe «confirmar» para eliminar el recurso.']);

        return DB::transaction(function () use ($equipo) {
            $equipo = Equipo::whereKey($equipo->id)->lockForUpdate()->firstOrFail();
            if ($equipo->activo) {
                return response()->json(['message' => 'Da de baja el recurso antes de eliminarlo del inventario.'], 409);
            }
            if (! $equipo->disponible || $equipo->prestamos()->whereNull('fecha_devolucion_real')->where('estado', 'activo')->exists()) {
                return response()->json(['message' => 'No se puede eliminar un recurso que tiene un préstamo sin devolver.'], 409);
            }

            $equipo->delete();
            Bitacora::registrar(
                'equipos.eliminar',
                "Eliminó del inventario el recurso {$equipo->codigo_inventario} (código {$equipo->codigo_barras}). Su historial se conserva.",
                entidad: $equipo,
                datos: $equipo->only(['codigo_inventario', 'codigo_barras', 'tipo', 'motivo_baja', 'dado_de_baja_el']),
            );

            return response()->noContent();
        });
    }
}
