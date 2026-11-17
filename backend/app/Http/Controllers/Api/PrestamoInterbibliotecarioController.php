<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\FichaInterbibliotecaria;
use App\Models\EjemplarEstadoHistorial;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\PrestamoInterbibliotecario;
use App\Models\ReservaLibro;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\HorarioBiblioteca;
use App\Support\Paginacion;
use App\Services\CatalogacionService;
use App\Services\CatalogarEjemplarService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Préstamo interbibliotecario (Catalogación › Préstamo interbibliotecario): el material que
 * otra biblioteca le presta a la nuestra. Se registra al llegar (una copia nueva, de un
 * título del catálogo o de uno que se cataloga con su ficha completa), se presta a los usuarios hasta
 * un día hábil antes de su fecha límite y, al devolverlo a su biblioteca, la copia sale del
 * catálogo sin borrarse: sus préstamos siguen contando en los reportes.
 */
class PrestamoInterbibliotecarioController extends Controller
{
    private const RELACIONES = [
        'ejemplar',
        'ejemplar.libro',
        'ejemplar.libro.autores',
        'ejemplar.libro.categorias',
        'ejemplar.libro.carreras',
        'ejemplar.libro.tipoMaterial',
        'ejemplar.libro.catalogadoPor:id,nombre',
        'ejemplar.ubicacion:id,nombre',
        'registradoPor:id,nombre',
        'devueltoPor:id,nombre',
    ];

    /**
     * Por páginas, con la pestaña (en_biblioteca, devueltos, todos) y la búsqueda (título,
     * autor, código o biblioteca). Lo que está en la biblioteca va del que vence primero al
     * último; lo devuelto, del más reciente al más antiguo. Cada fila trae su fecha tope, a
     * quién está prestada y cuántas veces se prestó.
     */
    public function index(Request $request)
    {
        $request->validate([
            'vista' => ['nullable', Rule::in(['en_biblioteca', 'devueltos', 'todos'])],
            'q' => ['nullable', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:255'],
        ]);

        $base = PrestamoInterbibliotecario::query();
        if (($codigo = trim((string) $request->query('codigo'))) !== '') {
            $base->whereHas('ejemplar', fn ($e) => $e->porCodigo($codigo));
        }
        $numeroFicha = BusquedaLibros::numeroDeFicha((string) $request->query('q'));
        if ($numeroFicha !== null) {
            $base->whereHas('ejemplar.libro', fn ($q) => $q->where('numero_ficha', $numeroFicha));
        }
        foreach ($numeroFicha !== null ? [] : Busqueda::palabras((string) $request->query('q')) as $palabra) {
            $base->where(fn (Builder $q) => $q
                ->whereSinTildes('biblioteca', $palabra)
                ->orWhereIn('ejemplar_id', Ejemplar::select('id')->whereIn('libro_id', BusquedaLibros::ids($palabra, BusquedaLibros::CAMPOS_PERSONAL)))
                ->orWhereIn('ejemplar_id', Ejemplar::select('id')->where(fn ($e) => $e->where('codigo_barras', 'ilike', "%{$palabra}%")->orWhere('codigo_lomo', 'ilike', "%{$palabra}%"))));
        }

        $vistas = [
            'en_biblioteca' => fn (Builder $q) => $q->whereNull('devuelto_el'),
            'devueltos' => fn (Builder $q) => $q->whereNotNull('devuelto_el'),
            'todos' => fn (Builder $q) => $q,
        ];
        $vista = $request->query('vista', 'en_biblioteca');

        $query = $vistas[$vista]((clone $base)->with(self::RELACIONES))
            ->withCount(['prestamos as total_prestamos']);
        $vista === 'en_biblioteca'
            ? $query->orderBy('fecha_limite')->orderBy('id')
            : $query->orderByRaw('devuelto_el IS NULL DESC')->orderByDesc('devuelto_el')->orderByDesc('fecha_limite')->orderByDesc('id');

        $pagina = $query->paginate(Paginacion::porPagina($request, 20, 100));
        $activos = Prestamo::with('usuario:id,nombre,apellido,rut')
            ->whereIn('ejemplar_id', $pagina->getCollection()->pluck('ejemplar_id'))
            ->where('estado', 'activo')
            ->get()
            ->keyBy('ejemplar_id');

        return response()->json([
            ...$pagina->through(fn (PrestamoInterbibliotecario $p) => $this->conDetalle($p, $activos->get($p->ejemplar_id)))->toArray(),
            'conteos' => collect($vistas)->map(fn ($filtro) => $filtro(clone $base)->count()),
            // Las bibliotecas que ya prestaron algo, para sugerirlas al registrar.
            'bibliotecas' => PrestamoInterbibliotecario::distinct()->orderBy('biblioteca')->pluck('biblioteca'),
        ]);
    }

    /** Hasta cuándo se le presta a los usuarios lo que tiene que volver a su biblioteca el fecha_limite. */
    public function fechaTope(Request $request)
    {
        $data = $request->validate([
            'fecha_limite' => ['required', 'date'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
        ]);

        return response()->json([
            'fecha_tope' => HorarioBiblioteca::diaHabilAnterior(Carbon::parse($data['fecha_limite']), $data['ubicacion_id'] ?? null)->toDateString(),
        ]);
    }

    /**
     * Registra lo que llegó: una copia nueva de un título del catálogo (libro_id) o de uno
     * que se cataloga con sus campos bibliográficos completos. Admite un lote de ejemplares.
     * La copia nace «Por colocar»: se presta cuando pasa a «En estante» (Estado de
     * ejemplares), y ahí atiende a la lista de espera del título si la tiene.
     */
    public function store(Request $request)
    {
        // Ingreso posterior a una ficha guardada: la biblioteca y el plazo los fija esa ficha,
        // nunca los campos arbitrarios de la pantalla de ejemplares.
        $cabecera = null;
        if ($request->has('ficha_interbibliotecaria_id')) {
            $request->validate(['ficha_interbibliotecaria_id' => ['required', 'integer', 'exists:fichas_interbibliotecarias,id'],
                'ficha' => ['prohibited'], 'libro_id' => ['prohibited']]);
            $cabecera = FichaInterbibliotecaria::findOrFail($request->input('ficha_interbibliotecaria_id'));
            $request->merge([...$cabecera->only(['libro_id', 'biblioteca', 'nota']),
                'fecha_ingreso' => $cabecera->fecha_ingreso->toDateString(), 'fecha_limite' => $cabecera->fecha_limite->toDateString()]);
        }
        $fichas = app(CatalogacionService::class);
        $copias = app(CatalogarEjemplarService::class);
        $lote = $request->has('ficha') || $request->has('ejemplares');
        if ($lote) {
            if (is_array($request->input('ejemplares'))) {
                $request->merge(['ejemplares' => array_map(fn ($fila) => is_array($fila)
                    ? array_replace($fila, ['codigo_barras' => trim((string) ($fila['codigo_barras'] ?? ''))]) : $fila,
                    $request->input('ejemplares'))]);
            }
            $reglas = [
                'libro_id' => ['required_without:ficha', 'nullable', 'exists:libros,id'],
                'ficha' => ['required_without:libro_id', 'nullable', 'array'],
                'ejemplares' => ['required', 'array', 'min:1', 'max:50'],
                'ejemplares.*' => ['required', 'array'],
                ...self::reglasPrestamo(),
                'fecha_ingreso' => ['required', 'date', 'before_or_equal:today'],
            ];
            $camposFicha = [...Libro::CAMPOS_BIBLIOGRAFICOS, 'autores', 'autores_roles', 'categorias', 'carreras'];
            foreach ($fichas->reglasCatalogacion() as $campo => $regla) {
                if (in_array(explode('.', $campo)[0], $camposFicha, true)) {
                    $reglas['ficha.'.$campo] = $regla;
                }
            }
            $reglas['ficha.titulo'] = ['required_without:libro_id', 'nullable', 'string', 'max:1000'];
            foreach ($copias->reglas() as $campo => $regla) {
                $reglas['ejemplares.*.'.$campo] = $regla;
            }
            $reglas['ejemplares.*.codigo_barras'][] = 'distinct';
            $data = $request->validate($reglas, self::MENSAJES + [
                'ficha.titulo.required_without' => 'Escribe el título de la ficha nueva o elige una ficha existente.',
                'ficha.isbn.unique' => 'Ya existe una ficha con ese ISBN: selecciónala para agregarle los ejemplares.',
                'ejemplares.*.codigo_barras.distinct' => 'Los ejemplares de esta recepción deben tener códigos distintos.',
            ]);
            $bibliografia = $data['ficha'] ?? [];
            $ejemplares = $data['ejemplares'];
        } else {
            $data = $request->validate([
                'libro_id' => ['required_without:titulo', 'nullable', 'exists:libros,id'],
                'titulo' => ['required_without:libro_id', 'nullable', 'string', 'max:1000'],
                // Títulos alternativos (246, repetible): uno por elemento
                'titulos_alternativos' => ['nullable', 'array'],
                'titulos_alternativos.*' => ['nullable', 'string', 'max:1000'],
                'autores' => ['nullable', 'array'],
                'autores.*' => ['string', 'max:255'],
                'isbn' => ['nullable', 'string', 'max:32', 'unique:libros,isbn'],
                'editorial' => ['nullable', 'string', 'max:255'],
                'anio_publicacion' => ['nullable', 'integer', 'min:1000', 'max:9999'],
                'tipo_material_id' => ['nullable', 'exists:tipos_material,id'],
                // Como cualquier copia: ni de otra copia (su código o el de lomo) ni de un equipo.
                'codigo_barras' => ['required', 'string', 'max:255', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo', 'unique:equipos,codigo_barras'],
                'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
                ...self::reglasPrestamo(),
                'fecha_ingreso' => ['required', 'date', 'before_or_equal:today'],
            ], [
                ...self::MENSAJES,
                'titulo.required_without' => 'Elige el título en el catálogo o escribe el título nuevo.',
                'isbn.unique' => 'Ya hay un título con ese ISBN en el catálogo: búscalo en «Ya está en el catálogo».',
                'fecha_ingreso.required' => 'Indica la fecha en que llegó.',
                'fecha_ingreso.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
            ]);

            $bibliografia = $data;
            if (isset($data['titulos_alternativos'])) {
                $bibliografia['titulo_alternativo'] = collect($data['titulos_alternativos'])->map(fn ($t) => trim((string) $t))->filter()->unique()->implode("\n") ?: null;
            }
            $ejemplares = [array_intersect_key($data, array_flip(['codigo_barras', 'ubicacion_id']))];
        }

        $registros = DB::transaction(function () use ($data, $request, $bibliografia, $ejemplares, $fichas, $copias, $lote, $cabecera) {
            $staff = $request->user();
            if (! empty($data['libro_id'])) {
                $libro = Libro::whereKey($data['libro_id'])->lockForUpdate()->firstOrFail();
            } else {
                $libro = Libro::create([
                    ...$fichas->soloCamposBibliograficos($bibliografia),
                    'catalogado_por_staff_id' => $staff->id,
                ]);
                $fichas->sincronizarPivotes($libro, $bibliografia);
            }
            if ($cabecera) {
                $cabecera = FichaInterbibliotecaria::whereKey($cabecera->id)->lockForUpdate()->firstOrFail();
                // Puede haberse editado mientras se preparaban los ejemplares: usa la versión actual.
                $data = [...$data, ...$cabecera->only(['biblioteca', 'nota']), 'fecha_ingreso' => $cabecera->fecha_ingreso->toDateString(), 'fecha_limite' => $cabecera->fecha_limite->toDateString()];
            } else {
                $cabecera = FichaInterbibliotecaria::create([
                    ...array_intersect_key($data, array_flip(['biblioteca', 'fecha_ingreso', 'fecha_limite', 'nota'])),
                    'libro_id' => $libro->id, 'registrado_por_staff_id' => $staff->id,
                ]);
            }
            $registros = [];
            foreach ($ejemplares as $i => $datosCopia) {
                $ejemplar = $copias->crear($libro->id, $datosCopia, 'por_colocar', $lote ? "ejemplares.{$i}." : '');
                $registros[] = PrestamoInterbibliotecario::create([
                    'ficha_interbibliotecaria_id' => $cabecera->id,
                    'ejemplar_id' => $ejemplar->id,
                    'biblioteca' => trim($data['biblioteca']),
                    'fecha_ingreso' => $data['fecha_ingreso'],
                    'fecha_limite' => $data['fecha_limite'],
                    'nota' => $data['nota'] ?? null,
                    'registrado_por_staff_id' => $staff->id,
                ]);
            }
            return $registros;
        });

        $detalles = [];
        foreach ($registros as $prestamo) {
            $prestamo->load(self::RELACIONES);
            $ejemplar = $prestamo->ejemplar;
            Bitacora::registrar(
                'libros.recibir_interbibliotecario',
                "Recibió en préstamo interbibliotecario «{$ejemplar->libro->titulo}» de {$prestamo->biblioteca} ({$ejemplar->copiaEnTexto()}, {$ejemplar->codigo_barras}, por colocar): vuelve a su biblioteca el {$prestamo->fecha_limite->format('d/m/Y')}",
                entidad: $prestamo,
                datos: ['numero_ficha' => $ejemplar->libro->numero_ficha, 'codigo_barras' => $ejemplar->codigo_barras, 'biblioteca' => $prestamo->biblioteca, 'fecha_limite' => $prestamo->fecha_limite->toDateString()],
            );
            $detalles[] = $this->conDetalle($prestamo, null);
        }
        return response()->json([
            ...$detalles[0],
            'numero_ficha' => $registros[0]->ejemplar->libro->numero_ficha,
            'cantidad' => count($detalles),
            'registros' => $detalles,
        ], 201);
    }

    /**
     * Corrige la biblioteca, la fecha límite (si la biblioteca propietaria da más plazo) o
     * la nota, mientras siga en la biblioteca. Los préstamos ya hechos no cambian de fecha.
     */
    public function update(Request $request, PrestamoInterbibliotecario $prestamoInterbibliotecario)
    {
        $prestamo = $prestamoInterbibliotecario;
        if ($prestamo->devuelto_el) {
            return response()->json(['message' => "Ya se devolvió a {$prestamo->biblioteca}: no se puede cambiar."], 409);
        }

        $data = $request->validate(
            array_map(fn (array $reglas) => ['sometimes', ...$reglas], self::reglasPrestamo($prestamo->fecha_ingreso->toDateString())),
            self::MENSAJES,
        );
        $data = array_map(fn ($valor) => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor, $data);

        $antes = $prestamo->only(array_keys($data));
        $prestamo->update($data);
        $prestamo->load(self::RELACIONES);

        Bitacora::registrar(
            'libros.editar_interbibliotecario',
            "Editó el préstamo interbibliotecario de «{$prestamo->ejemplar->libro->titulo}» ({$prestamo->ejemplar->codigo_barras}) de {$prestamo->biblioteca}",
            entidad: $prestamo,
            datos: Bitacora::cambios(
                array_map(fn ($v) => $v instanceof Carbon ? $v->toDateString() : $v, $antes),
                array_map(fn ($v) => $v instanceof Carbon ? $v->toDateString() : $v, $prestamo->only(array_keys($data))),
            ),
        );

        $activo = Prestamo::with('usuario:id,nombre,apellido,rut')->where('ejemplar_id', $prestamo->ejemplar_id)->where('estado', 'activo')->first();

        return response()->json($this->conDetalle($prestamo->loadCount('prestamos as total_prestamos'), $activo));
    }

    /**
     * Se devuelve a su biblioteca: la copia pasa a «Devuelto a su biblioteca» y sale del
     * catálogo y del portal, pero no se borra (sus préstamos siguen en los reportes y en el
     * historial). No se puede mientras esté apartada para alguien. Si figura prestada a un
     * usuario pero ya no la tiene (volvió sin registrarse, o se perdió y se arregló con la
     * biblioteca propietaria), el personal la cierra igual indicando el motivo y escribiendo
     * CONFIRMAR: ese préstamo queda devuelto hoy, sin multa automática (si corresponde
     * cobrar, se cursa en Multas). Lo puede hacer catalogación o el mesón (Préstamos).
     * Si el título se queda sin otras copias que se presten, su lista de espera se cancela.
     */
    public function devolver(Request $request, PrestamoInterbibliotecario $prestamoInterbibliotecario)
    {
        $data = $request->validate([
            'nota' => ['nullable', 'string', 'max:2000'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'confirmacion' => ['nullable', Rule::in(['CONFIRMAR'])],
        ], [
            'confirmacion.in' => 'Escribe CONFIRMAR para cerrar el préstamo y devolverla a su biblioteca.',
        ]);

        $resultado = DB::transaction(function () use ($prestamoInterbibliotecario, $request, $data) {
            $ejemplar = Ejemplar::whereKey($prestamoInterbibliotecario->ejemplar_id)->lockForUpdate()->first();
            $prestamo = PrestamoInterbibliotecario::whereKey($prestamoInterbibliotecario->id)->lockForUpdate()->first();

            if ($prestamo->devuelto_el) {
                return ['error' => "Ya se devolvió a {$prestamo->biblioteca} el {$prestamo->devuelto_el->format('d/m/Y')}."];
            }

            $activo = Prestamo::with('usuario:id,nombre,apellido,rut,tipo_documento,pais')->where('ejemplar_id', $ejemplar->id)->where('estado', 'activo')->lockForUpdate()->first();
            $motivo = trim((string) ($data['motivo'] ?? ''));
            if ($activo && ($motivo === '' || ($data['confirmacion'] ?? null) !== 'CONFIRMAR')) {
                return ['error' => "Figura prestada a {$activo->usuario?->descripcion()} hasta el {$activo->fecha_devolucion?->format('d/m/Y')}. Si está aquí, recíbela en Préstamos; si ya no la tiene, indica el motivo y escribe CONFIRMAR para cerrar ese préstamo."];
            }

            $apartada = ReservaLibro::with('usuario')->where('ejemplar_id', $ejemplar->id)->where('estado', 'pendiente')->first();
            if ($apartada) {
                return ['error' => "Está apartada para {$apartada->usuario?->descripcion()}, que la reservó: cancela esa reserva antes de devolverla."];
            }

            $staff = $request->user();
            if ($activo) {
                $activo->update([
                    'estado' => 'devuelto',
                    'fecha_devolucion_real' => now(),
                    'devuelto_por' => $staff->nombre,
                    'devuelto_por_staff_id' => $staff->id,
                ]);

                Bitacora::registrar(
                    'prestamos.cerrar_por_devolucion_interbibliotecaria',
                    "Cerró sin multa el préstamo de «{$activo->libro_titulo}» al devolver la copia a {$prestamo->biblioteca} (préstamo interbibliotecario). Motivo: {$motivo}",
                    entidad: $activo,
                    datos: ['codigo_barras' => $activo->codigo_barras, 'motivo' => $motivo],
                    afectado: $activo->usuario,
                );
            }

            $nota = trim(collect([
                trim((string) ($data['nota'] ?? '')),
                $activo ? "Figuraba prestada a {$activo->usuario?->descripcion()}; préstamo cerrado al devolverla: {$motivo}" : '',
            ])->filter()->implode("\n"));
            $prestamo->update([
                'devuelto_el' => today(),
                'devuelto_por_staff_id' => $staff->id,
                'nota' => $nota !== '' ? trim("{$prestamo->nota}\n{$nota}") : $prestamo->nota,
            ]);

            EjemplarEstadoHistorial::create([
                'ejemplar_id' => $ejemplar->id,
                'estado_anterior' => $ejemplar->estado_proceso,
                'estado_nuevo' => 'devuelto_propietaria',
                'estado_personalizado_anterior_id' => $ejemplar->estado_personalizado_id,
                'estado_personalizado_nuevo_id' => null,
                'staff_id' => $staff->id,
                'motivo' => "Devuelto a {$prestamo->biblioteca} (préstamo interbibliotecario)".($activo ? ". Figuraba prestada: {$motivo}" : ''),
            ]);
            $ejemplar->update(['estado_proceso' => 'devuelto_propietaria', 'estado_personalizado_id' => null, 'disponible' => false]);

            // Sin otra copia que se pueda prestar, nadie más va a recibir este título.
            $quedanCopias = Ejemplar::where('libro_id', $ejemplar->libro_id)->whereKeyNot($ejemplar->id)
                ->whereNotIn('estado_proceso', ['de_baja', 'devuelto_propietaria'])->exists();
            $enCola = $quedanCopias ? 0 : ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('estado', 'en_cola')->update(['estado' => 'cancelado']);

            return ['prestamo' => $prestamo, 'en_cola' => $enCola, 'cerrado' => $activo];
        });

        if (isset($resultado['error'])) {
            return response()->json(['message' => $resultado['error']], 409);
        }

        $prestamo = $resultado['prestamo']->load(self::RELACIONES)->loadCount('prestamos as total_prestamos');
        Bitacora::registrar(
            'libros.devolver_interbibliotecario',
            "Devolvió a {$prestamo->biblioteca} «{$prestamo->ejemplar->libro->titulo}» ({$prestamo->ejemplar->copiaEnTexto()}, {$prestamo->ejemplar->codigo_barras}), préstamo interbibliotecario"
                .($resultado['en_cola'] ? ". {$resultado['en_cola']} ".($resultado['en_cola'] === 1 ? 'reserva en espera cancelada' : 'reservas en espera canceladas') : ''),
            entidad: $prestamo,
            datos: ['codigo_barras' => $prestamo->ejemplar->codigo_barras, 'biblioteca' => $prestamo->biblioteca, 'en_cola' => $resultado['en_cola'], 'prestamo_cerrado' => $resultado['cerrado']?->id],
            afectado: $resultado['cerrado']?->usuario,
        );

        return response()->json([
            ...$this->conDetalle($prestamo, null),
            'reservas_canceladas' => $resultado['en_cola'],
            // A quién se le cerró el préstamo (figuraba prestada), para el aviso.
            'prestamo_cerrado' => $resultado['cerrado'] ? trim("{$resultado['cerrado']->usuario?->nombre} {$resultado['cerrado']->usuario?->apellido}") : null,
        ]);
    }

    private const MENSAJES = [
        'biblioteca.required' => 'Indica la biblioteca propietaria.',
        'fecha_limite.required' => 'Indica la fecha en que tiene que volver a su biblioteca.',
        'fecha_limite.after' => 'La fecha límite tiene que ser posterior a la de ingreso.',
    ];

    /** Lo que se registra y se puede corregir: de quién es, hasta cuándo y una nota. */
    private static function reglasPrestamo(string $fechaIngreso = 'fecha_ingreso'): array
    {
        return [
            'biblioteca' => ['required', 'string', 'max:255'],
            'fecha_limite' => ['required', 'date', "after:{$fechaIngreso}"],
            'nota' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** La fila con su fecha tope, a quién está prestada ahora y si ya pasó el plazo. */
    private function conDetalle(PrestamoInterbibliotecario $prestamo, ?Prestamo $activo): array
    {
        $tope = $prestamo->fechaTope();

        return [
            ...$prestamo->toArray(),
            'fecha_tope' => $tope->toDateString(),
            // Ya no se presta: pasó el último día para devolverla un usuario.
            'vencido' => ! $prestamo->devuelto_el && $tope->lt(today()),
            'prestamo_activo' => $activo ? [
                'id' => $activo->id,
                'usuario' => $activo->usuario ? trim("{$activo->usuario->nombre} {$activo->usuario->apellido}") : null,
                'rut' => $activo->usuario?->rut,
                'fecha_devolucion' => $activo->fecha_devolucion?->toDateString(),
            ] : null,
        ];
    }
}
