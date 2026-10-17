<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\EjemplarEstadoHistorial;
use App\Support\Bitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EjemplarController extends Controller
{
    private const ESTADOS = 'inventario,procesos_tecnicos,por_colocar,en_estante,estanteria_auxiliar,de_baja,coleccion_movil,personalizado';

    /** Busca una copia física por su código de barras o su código de lomo (escaneo en préstamo/reserva/estado). */
    public function buscarPorCodigo(string $codigo)
    {
        $ejemplar = Ejemplar::porCodigo($codigo)->with(['libro', 'ubicacion'])->first();

        if (! $ejemplar) {
            return response()->json(['message' => 'Código de barras no encontrado en el sistema'], 404);
        }

        return response()->json($ejemplar);
    }

    /** Agrega una copia nueva a un libro (obra) ya catalogado. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'libro_id' => ['required', 'exists:libros,id'],
            'codigo_barras' => ['required', 'string', 'max:255', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'volumen' => ['nullable', 'string', 'max:100'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'forma_adquisicion' => ['nullable', Rule::in(Ejemplar::FORMAS_ADQUISICION)],
        ]);

        $ejemplar = DB::transaction(function () use ($data) {
            $siguienteNumero = Ejemplar::where('libro_id', $data['libro_id'])->max('numero_copia') + 1;

            return Ejemplar::create([
                'libro_id' => $data['libro_id'],
                'numero_copia' => $siguienteNumero,
                'codigo_barras' => $data['codigo_barras'],
                'ubicacion_id' => $data['ubicacion_id'] ?? null,
                'volumen' => $data['volumen'] ?? null,
                'precio' => $data['precio'] ?? null,
                'forma_adquisicion' => $data['forma_adquisicion'] ?? null,
                'estado_proceso' => 'inventario',
            ]);
        });

        // refresh(): el código de lomo lo pone la base al insertar.
        $ejemplar->refresh()->load(['libro', 'ubicacion']);
        Bitacora::registrar(
            'libros.agregar_copia',
            "Agregó la copia {$ejemplar->numero_copia} de «{$ejemplar->libro?->titulo}» ({$ejemplar->codigo_barras}, lomo {$ejemplar->codigo_lomo})",
            entidad: $ejemplar,
            datos: ['codigo_barras' => $ejemplar->codigo_barras, 'codigo_lomo' => $ejemplar->codigo_lomo],
        );

        return response()->json($ejemplar, 201);
    }

    /**
     * Sugiere el próximo código de barras interno secuencial. Los códigos reales de la
     * biblioteca son numéricos de 14 dígitos (ej. 30000003227565, formato heredado de
     * Horizon) — se busca el mayor código de 14 dígitos existente y se suma 1. El staff
     * puede editarlo antes de guardar. Si todavía no hay ningún código de 14 dígitos en
     * la base (instalación nueva, sin códigos reales importados aún), arranca en
     * '00000000000001' — es solo un punto de partida neutro, no un prefijo institucional
     * real, que nadie confirmó todavía.
     */
    public function siguienteCodigoBarras()
    {
        $ultimoNumero = Ejemplar::whereRaw("codigo_barras ~ '^[0-9]{14}$'")
            ->get(['codigo_barras'])
            ->map(fn ($e) => (int) $e->codigo_barras)
            ->max();

        $codigo = str_pad((string) (($ultimoNumero ?? 0) + 1), 14, '0', STR_PAD_LEFT);

        return response()->json(['codigo_barras' => $codigo]);
    }

    public function cambiarEstado(Request $request, Ejemplar $ejemplar)
    {
        $data = $request->validate(
            [...$this->reglasEstado(), 'motivo' => ['required_if:estado_proceso,de_baja', 'nullable', 'string', 'max:500']],
            ['motivo.required_if' => 'Indica el motivo de la baja: queda registrado en el historial.'],
        );

        if ($data['estado_proceso'] === 'de_baja' && ! $ejemplar->disponible) {
            return response()->json(['message' => 'No se puede dar de baja un ejemplar actualmente prestado o reservado'], 409);
        }

        $estadoAnterior = $ejemplar->estado_proceso;

        DB::transaction(function () use ($ejemplar, $data, $request) {
            $this->aplicarCambioDeEstado($ejemplar, $data['estado_proceso'], $data['estado_personalizado_id'] ?? null, $request->user()->id, null, $data['motivo'] ?? null);
        });

        $ejemplar->loadMissing('libro:id,titulo');
        Bitacora::registrar(
            $data['estado_proceso'] === 'de_baja' ? 'libros.dar_de_baja' : 'libros.cambiar_estado',
            "Cambió el estado de «{$ejemplar->libro?->titulo}» copia {$ejemplar->numero_copia} ({$ejemplar->codigo_barras}): ".self::nombreEstado($estadoAnterior).' → '.self::nombreEstado($data['estado_proceso'])
                .(! empty($data['motivo']) ? ". Motivo: {$data['motivo']}" : ''),
            entidad: $ejemplar,
            datos: ['estado' => [$estadoAnterior, $data['estado_proceso']], 'motivo' => $data['motivo'] ?? null],
        );

        return response()->json($ejemplar->fresh(['libro', 'estadoPersonalizado', 'ubicacion']));
    }

    /**
     * Datos para imprimir etiquetas de lomo: por cada código escaneado o escrito (el de
     * 14 dígitos o el de lomo) y/o por cada título elegido (todas sus copias), la
     * clasificación del libro y el código de lomo de la copia. No escribe nada.
     */
    public function etiquetasLomo(Request $request)
    {
        $data = $request->validate([
            'codigos' => ['nullable', 'array', 'max:500'],
            'codigos.*' => ['string', 'max:255'],
            'libro_ids' => ['nullable', 'array', 'max:200'],
            'libro_ids.*' => ['integer'],
        ]);

        $etiqueta = fn (Ejemplar $e) => [
            'id' => $e->id,
            'codigo_barras' => $e->codigo_barras,
            'codigo_lomo' => $e->codigo_lomo,
            'numero_copia' => $e->numero_copia,
            'volumen' => $e->volumen,
            'titulo' => $e->libro?->titulo,
            'clasificacion' => $e->libro?->clasificacion,
        ];

        $encontradas = collect();
        $noEncontrados = [];

        foreach (array_unique(array_filter(array_map('trim', $data['codigos'] ?? []))) as $codigo) {
            $ejemplar = Ejemplar::porCodigo($codigo)->with('libro:id,titulo,clasificacion')->first();
            $ejemplar ? $encontradas->push($ejemplar) : $noEncontrados[] = $codigo;
        }

        if (! empty($data['libro_ids'])) {
            $encontradas = $encontradas->concat(
                Ejemplar::whereIn('libro_id', $data['libro_ids'])->with('libro:id,titulo,clasificacion')->orderBy('libro_id')->orderBy('numero_copia')->get()
            );
        }

        return response()->json([
            'etiquetas' => $encontradas->unique('id')->values()->map($etiqueta),
            'no_encontrados' => $noEncontrados,
        ]);
    }

    /** Vista previa de un cambio masivo: cuenta y muestra los ejemplares que matchean el filtro, sin escribir nada. */
    public function cambioMasivoPreview(Request $request)
    {
        $this->validarFiltro($request);

        $query = $this->filtrarEjemplares($request);
        $total = (clone $query)->count();
        $muestra = (clone $query)->with(['libro:id,titulo', 'ubicacion:id,nombre'])->orderBy('id')->limit(10)->get(['id', 'libro_id', 'codigo_barras', 'numero_copia', 'estado_proceso', 'ubicacion_id']);

        return response()->json(['total' => $total, 'muestra' => $muestra]);
    }

    /**
     * Ejecuta el cambio masivo: vuelve a aplicar el MISMO filtro server-side (no confía
     * en una lista de IDs que el cliente pudo haber cacheado stale desde el preview) y
     * actualiza cada ejemplar dentro de una transacción, con un lote_id compartido en el
     * historial para poder ver después "qué se cambió junto en esta operación".
     */
    public function cambioMasivoEjecutar(Request $request)
    {
        $this->validarFiltro($request);
        $data = $request->validate($this->reglasEstado('nuevo_estado'));

        $loteId = (string) Str::uuid();
        $staffId = $request->user()->id;

        $totalActualizados = DB::transaction(function () use ($request, $data, $loteId, $staffId) {
            $ejemplares = $this->filtrarEjemplares($request)->lockForUpdate()->get();
            $actualizados = 0;

            foreach ($ejemplares as $ejemplar) {
                // Igual que en el cambio individual: un ejemplar actualmente prestado o
                // reservado no se puede dar de baja — se excluye del lote en vez de fallar
                // la operación completa por uno solo ocupado.
                if ($data['nuevo_estado'] === 'de_baja' && ! $ejemplar->disponible) {
                    continue;
                }

                $this->aplicarCambioDeEstado($ejemplar, $data['nuevo_estado'], $data['estado_personalizado_id'] ?? null, $staffId, $loteId, $data['motivo'] ?? null);
                $actualizados++;
            }

            return $actualizados;
        });

        Bitacora::registrar(
            'libros.cambio_masivo',
            "Cambio masivo de estado: {$totalActualizados} ".($totalActualizados === 1 ? 'copia pasó' : 'copias pasaron').' a '.self::nombreEstado($data['nuevo_estado']),
            datos: [
                'lote_id' => $loteId,
                'total' => $totalActualizados,
                'filtro' => array_filter($request->only(['ubicacion_id', 'estado_proceso_actual', 'tipo_material_id', 'categoria_id', 'q'])),
                'motivo' => $data['motivo'] ?? null,
            ],
        );

        return response()->json(['lote_id' => $loteId, 'total_actualizados' => $totalActualizados]);
    }

    public function historialEstado(Request $request)
    {
        $query = EjemplarEstadoHistorial::with([
            'ejemplar:id,libro_id,codigo_barras,numero_copia',
            'ejemplar.libro:id,titulo',
            'staff:id,nombre',
            'estadoPersonalizadoAnterior:id,nombre',
            'estadoPersonalizadoNuevo:id,nombre',
        ]);

        if ($ejemplarId = $request->query('ejemplar_id')) {
            $query->where('ejemplar_id', $ejemplarId);
        }

        if ($loteId = $request->query('lote_id')) {
            $query->where('lote_id', $loteId);
        }

        if ($staffId = $request->query('staff_id')) {
            $query->where('staff_id', $staffId);
        }

        if ($busqueda = $request->query('q')) {
            $query->whereHas('ejemplar', function ($e) use ($busqueda) {
                $e->where(fn ($c) => $c->where('codigo_barras', 'ilike', "%{$busqueda}%")->orWhere('codigo_lomo', 'like', "%{$busqueda}%"))
                    ->orWhereHas('libro', fn ($l) => $l->whereSinTildes('titulo', $busqueda));
            });
        }

        if ($desde = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $desde);
        }

        if ($hasta = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $hasta);
        }

        // El estado al que pasó la copia (los chips de estado del Historial de estado).
        if ($estado = $request->query('estado')) {
            $query->where('estado_nuevo', $estado);
        }

        // Cuántas copias movió en total el cambio masivo de cada fila («Lote 3a9dbcff · 2 copias»),
        // aunque los filtros o el límite dejen ver solo algunas. 0 en los cambios individuales.
        $query->addSelect(['lote_total' => EjemplarEstadoHistorial::from('ejemplar_estado_historial as del_lote')
            ->selectRaw('count(*)')
            ->whereColumn('del_lote.lote_id', 'ejemplar_estado_historial.lote_id'),
        ])->withCasts(['lote_total' => 'integer']);

        return response()->json($query->latest()->limit(200)->get());
    }

    private static function nombreEstado(string $estado): string
    {
        return [
            'inventario' => 'Inventario',
            'procesos_tecnicos' => 'Procesos técnicos',
            'por_colocar' => 'Por colocar',
            'en_estante' => 'En estante',
            'estanteria_auxiliar' => 'Estantería auxiliar',
            'coleccion_movil' => 'Colección móvil',
            'personalizado' => 'Personalizado',
            'de_baja' => 'De baja',
        ][$estado] ?? $estado;
    }

    private function aplicarCambioDeEstado(Ejemplar $ejemplar, string $nuevoEstado, ?int $estadoPersonalizadoId, int $staffId, ?string $loteId = null, ?string $motivo = null): void
    {
        $estadoAnterior = $ejemplar->estado_proceso;
        $estadoPersonalizadoAnteriorId = $ejemplar->estado_personalizado_id;
        $estadoPersonalizadoNuevoId = $nuevoEstado === 'personalizado' ? $estadoPersonalizadoId : null;

        $ejemplar->update([
            'estado_proceso' => $nuevoEstado,
            'estado_personalizado_id' => $estadoPersonalizadoNuevoId,
            'fecha_inventario' => $nuevoEstado === 'inventario' ? now() : $ejemplar->fecha_inventario,
        ]);

        EjemplarEstadoHistorial::create([
            'ejemplar_id' => $ejemplar->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $nuevoEstado,
            'estado_personalizado_anterior_id' => $estadoPersonalizadoAnteriorId,
            'estado_personalizado_nuevo_id' => $estadoPersonalizadoNuevoId,
            'staff_id' => $staffId,
            'lote_id' => $loteId,
            'motivo' => $motivo,
        ]);
    }

    private function filtrarEjemplares(Request $request): Builder
    {
        $query = Ejemplar::query();

        if ($ubicacionId = $request->input('ubicacion_id')) {
            $query->where('ubicacion_id', $ubicacionId);
        }

        if ($estadoActual = $request->input('estado_proceso_actual')) {
            $query->where('estado_proceso', $estadoActual);
        }

        if ($tipoMaterialId = $request->input('tipo_material_id')) {
            $query->whereHas('libro', fn ($q) => $q->where('tipo_material_id', $tipoMaterialId));
        }

        if ($categoriaId = $request->input('categoria_id')) {
            $query->whereHas('libro.categorias', fn ($q) => $q->where('categorias.id', $categoriaId));
        }

        if ($busqueda = $request->input('q')) {
            $query->where(function ($qq) use ($busqueda) {
                $qq->where('codigo_barras', 'ilike', "%{$busqueda}%")
                    ->orWhere('codigo_lomo', 'like', "%{$busqueda}%")
                    ->orWhereHas('libro', fn ($l) => $l->whereSinTildes('titulo', $busqueda));
            });
        }

        return $query;
    }

    /** Exige al menos un criterio — sin esto, un cambio masivo sin filtros aplicaría a TODOS los ejemplares del sistema. */
    private function validarFiltro(Request $request): void
    {
        $tieneFiltro = collect(['ubicacion_id', 'estado_proceso_actual', 'tipo_material_id', 'categoria_id', 'q'])
            ->contains(fn ($campo) => filled($request->input($campo)));

        if (! $tieneFiltro) {
            abort(422, 'Debes indicar al menos un criterio de filtro (ubicación, estado actual, tipo de material, categoría o búsqueda) para un cambio masivo.');
        }
    }

    private function reglasEstado(string $campoEstado = 'estado_proceso'): array
    {
        return [
            $campoEstado => ['required', Rule::in(explode(',', self::ESTADOS))],
            'estado_personalizado_id' => ['required_if:'.$campoEstado.',personalizado', 'nullable', 'exists:estados_libro_personalizados,id'],
            'motivo' => ['nullable', 'string'],
        ];
    }
}
