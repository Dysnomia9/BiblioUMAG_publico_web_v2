<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\EjemplarEstadoHistorial;
use App\Models\ReservaLibro;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\Paginacion;
use App\Services\BajaEjemplarService;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EjemplarController extends Controller
{
    private const ESTADOS = 'inventario,procesos_tecnicos,por_colocar,en_estante,estanteria_auxiliar,de_baja,coleccion_movil,personalizado';

    public function __construct(private ReservaLibroService $reservaLibroService, private BajaEjemplarService $bajas)
    {
    }

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
            ...self::reglasNotasYColeccion(),
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
                'nota_publica' => $data['nota_publica'] ?? null,
                'nota_interna' => $data['nota_interna'] ?? null,
                'coleccion' => $data['coleccion'] ?? null,
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
     * Edita los datos de una copia ya creada: ubicación, volumen, precio, forma de
     * adquisición, colección y notas. El estado tiene su propio camino (cambiarEstado),
     * con historial; los códigos de barras no se cambian.
     */
    public function update(Request $request, Ejemplar $ejemplar)
    {
        $data = $request->validate([
            'ubicacion_id' => ['sometimes', 'nullable', 'exists:ubicaciones,id'],
            'volumen' => ['sometimes', 'nullable', 'string', 'max:100'],
            'precio' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'forma_adquisicion' => ['sometimes', 'nullable', Rule::in(Ejemplar::FORMAS_ADQUISICION)],
            ...array_map(fn (array $reglas) => ['sometimes', ...$reglas], self::reglasNotasYColeccion()),
        ]);

        // Texto en blanco = sin dato: la copia vuelve a mostrar la colección del libro.
        $data = array_map(fn ($valor) => is_string($valor) && trim($valor) === '' ? null : $valor, $data);

        $antes = $ejemplar->only(array_keys($data));
        $ejemplar->update($data);
        $ejemplar->loadMissing('libro:id,titulo,coleccion');

        Bitacora::registrar(
            'libros.editar_copia',
            "Editó la copia {$ejemplar->numero_copia} de «{$ejemplar->libro?->titulo}» ({$ejemplar->codigo_barras})",
            entidad: $ejemplar,
            datos: Bitacora::cambios($antes, $ejemplar->only(array_keys($data))),
        );

        return response()->json($ejemplar->fresh(['libro', 'ubicacion', 'estadoPersonalizado']));
    }

    /** Colección y notas de la copia (las tres opcionales). */
    private static function reglasNotasYColeccion(): array
    {
        return [
            'coleccion' => ['nullable', 'string', 'max:255'],
            'nota_publica' => ['nullable', 'string', 'max:5000'],
            'nota_interna' => ['nullable', 'string', 'max:5000'],
        ];
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
        // El mayor se calcula en la base, sin traer los miles de códigos: todos tienen 14
        // dígitos, así que el mayor como texto es también el mayor como número.
        $ultimo = Ejemplar::whereRaw("codigo_barras ~ '^[0-9]{14}$'")->max('codigo_barras');

        $codigo = str_pad((string) ((int) ($ultimo ?? 0) + 1), 14, '0', STR_PAD_LEFT);

        return response()->json(['codigo_barras' => $codigo]);
    }

    public function cambiarEstado(Request $request, Ejemplar $ejemplar)
    {
        $data = $request->validate(
            [
                ...$this->reglasEstado(),
                'motivo' => ['required_if:estado_proceso,de_baja', 'nullable', 'string', 'max:500'],
                // Dar de baja saca la copia de circulación (y cierra su préstamo si estaba prestada): se pide escribir CONFIRMAR.
                'confirmacion' => ['required_if:estado_proceso,de_baja', 'nullable', Rule::in(['CONFIRMAR'])],
            ],
            [
                'motivo.required_if' => 'Indica el motivo de la baja: queda registrado en el historial.',
                'confirmacion.required_if' => 'Escribe CONFIRMAR para dar de baja la copia.',
                'confirmacion.in' => 'Escribe CONFIRMAR para dar de baja la copia.',
            ],
        );

        // La copia queda bloqueada mientras cambia: un préstamo o una reserva que llega al
        // mismo tiempo espera, y la decisión se toma con su disponibilidad al día.
        [$estadoAnterior, $apartada, $bloqueo, $prestamoCerrado] = DB::transaction(function () use ($ejemplar, $data, $request) {
            $ejemplar = Ejemplar::whereKey($ejemplar->id)->lockForUpdate()->first();

            if ($data['estado_proceso'] === 'de_baja') {
                if ($bloqueo = $this->bajas->bloqueo($ejemplar)) {
                    return [null, null, $bloqueo, null];
                }

                $anterior = $ejemplar->estado_proceso;

                return [$anterior, null, null, $this->bajas->darDeBaja($ejemplar, $data['motivo'], $request->user())];
            }

            $anterior = $ejemplar->estado_proceso;
            if ($anterior === 'de_baja') {
                $this->bajas->reincorporar($ejemplar);
            }

            return [
                $anterior,
                $this->aplicarCambioDeEstado($ejemplar->refresh(), $data['estado_proceso'], $data['estado_personalizado_id'] ?? null, $request->user()->id, null, $data['motivo'] ?? null),
                null,
                null,
            ];
        });

        if ($bloqueo) {
            return response()->json(['message' => $bloqueo], 409);
        }

        $ejemplar->refresh()->loadMissing('libro:id,titulo');
        Bitacora::registrar(
            $data['estado_proceso'] === 'de_baja' ? 'libros.dar_de_baja' : 'libros.cambiar_estado',
            "Cambió el estado de «{$ejemplar->libro?->titulo}» copia {$ejemplar->numero_copia} ({$ejemplar->codigo_barras}): ".self::nombreEstado($estadoAnterior).' → '.self::nombreEstado($data['estado_proceso'])
                .(! empty($data['motivo']) ? ". Motivo: {$data['motivo']}" : '')
                .($prestamoCerrado ? ". Estaba prestada a {$prestamoCerrado->usuario?->descripcion()}: el préstamo quedó cerrado por la baja" : '')
                .($apartada ? ". Quedó apartada para {$apartada->usuario?->descripcion()}, primera en la lista de espera" : ''),
            entidad: $ejemplar,
            datos: ['estado' => [$estadoAnterior, $data['estado_proceso']], 'motivo' => $data['motivo'] ?? null],
            afectado: $prestamoCerrado?->usuario ?? $apartada?->usuario,
        );

        return response()->json($ejemplar->fresh(['libro', 'estadoPersonalizado', 'ubicacion'])
            ->setAttribute('apartada_para', $apartada ? trim("{$apartada->usuario?->nombre} {$apartada->usuario?->apellido}") : null));
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
        // Como en el cambio individual, una baja siempre dice por qué.
        $data = $request->validate(
            [...$this->reglasEstado('nuevo_estado'), 'motivo' => ['required_if:nuevo_estado,de_baja', 'nullable', 'string', 'max:500']],
            ['motivo.required_if' => 'Indica el motivo de la baja: queda registrado en el historial de cada copia.'],
        );

        $loteId = (string) Str::uuid();
        $staffId = $request->user()->id;

        [$totalActualizados, $apartadas] = DB::transaction(function () use ($request, $data, $loteId, $staffId) {
            $ejemplares = $this->filtrarEjemplares($request)->lockForUpdate()->get();
            $actualizados = 0;
            $apartadas = 0;

            foreach ($ejemplares as $ejemplar) {
                // Igual que en el cambio individual: un ejemplar actualmente prestado o
                // reservado no se puede dar de baja — se excluye del lote en vez de fallar
                // la operación completa por uno solo ocupado.
                if ($data['nuevo_estado'] === 'de_baja' && ! $ejemplar->disponible) {
                    continue;
                }

                if ($this->aplicarCambioDeEstado($ejemplar, $data['nuevo_estado'], $data['estado_personalizado_id'] ?? null, $staffId, $loteId, $data['motivo'] ?? null)) {
                    $apartadas++;
                }
                $actualizados++;
            }

            return [$actualizados, $apartadas];
        });

        Bitacora::registrar(
            'libros.cambio_masivo',
            "Cambio masivo de estado: {$totalActualizados} ".($totalActualizados === 1 ? 'copia pasó' : 'copias pasaron').' a '.self::nombreEstado($data['nuevo_estado'])
                .($apartadas ? " ({$apartadas} ".($apartadas === 1 ? 'quedó apartada' : 'quedaron apartadas').' para la lista de espera)' : ''),
            datos: [
                'lote_id' => $loteId,
                'total' => $totalActualizados,
                'apartadas' => $apartadas,
                'filtro' => array_filter($request->only(['ubicacion_id', 'estado_proceso_actual', 'tipo_material_id', 'categoria_id', 'q'])),
                'motivo' => $data['motivo'] ?? null,
            ],
        );

        return response()->json(['lote_id' => $loteId, 'total_actualizados' => $totalActualizados, 'apartadas' => $apartadas]);
    }

    /**
     * Las copias dadas de baja (pestaña «Eliminados» de Administración), de la baja más
     * reciente a la más antigua, con el motivo, quién la hizo y cuándo. Se reincorporan
     * cambiándoles el estado (PATCH /ejemplares/{id}/estado).
     */
    public function dadasDeBaja(Request $request)
    {
        $ultimaBaja = fn () => DB::table('ejemplar_estado_historial as h')
            ->whereColumn('h.ejemplar_id', 'ejemplares.id')
            ->where('h.estado_nuevo', 'de_baja')
            ->orderByDesc('h.id')
            ->limit(1);

        $query = Ejemplar::where('estado_proceso', 'de_baja')
            ->with(['libro:id,titulo,tipo_material_id', 'libro.tipoMaterial:id,nombre', 'ubicacion:id,nombre'])
            ->addSelect([
                'ejemplares.*',
                'baja_motivo' => $ultimaBaja()->select('h.motivo'),
                'baja_en' => $ultimaBaja()->select('h.created_at'),
                'baja_por' => $ultimaBaja()->join('staff as s', 's.id', '=', 'h.staff_id')->select('s.nombre'),
            ]);

        foreach (Busqueda::palabras((string) $request->query('q')) as $palabra) {
            $query->where(fn ($q) => $q
                ->whereIn('ejemplares.libro_id', BusquedaLibros::ids($palabra, BusquedaLibros::CAMPOS_PERSONAL))
                ->orWhere('ejemplares.codigo_barras', 'ilike', "%{$palabra}%")
                ->orWhere('ejemplares.codigo_lomo', 'ilike', "%{$palabra}%"));
        }

        return response()->json($query->orderByDesc('baja_en')->orderByDesc('ejemplares.id')->paginate(Paginacion::porPagina($request, 20, 100)));
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

    /**
     * $ejemplar llega bloqueado. Si vuelve al estante disponible y hay lista de espera de
     * su título, queda apartada para el primero de la fila (no para quien llegue antes al
     * mesón) y se devuelve esa reserva.
     */
    private function aplicarCambioDeEstado(Ejemplar $ejemplar, string $nuevoEstado, ?int $estadoPersonalizadoId, int $staffId, ?string $loteId = null, ?string $motivo = null): ?ReservaLibro
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

        if ($nuevoEstado === 'en_estante' && $estadoAnterior !== 'en_estante' && $ejemplar->disponible) {
            return $this->reservaLibroService->liberarLibro($ejemplar);
        }

        return null;
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
