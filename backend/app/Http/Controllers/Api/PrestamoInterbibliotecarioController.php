<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Ejemplar;
use App\Models\EjemplarEstadoHistorial;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\PrestamoInterbibliotecario;
use App\Models\ReservaLibro;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\HorarioBiblioteca;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Préstamo interbibliotecario (Catalogación › Préstamo interbibliotecario): el material que
 * otra biblioteca le presta a la nuestra. Se registra al llegar (una copia nueva, de un
 * título del catálogo o de uno que se crea con lo básico), se presta a los usuarios hasta
 * un día hábil antes de su fecha límite y, al devolverlo a su biblioteca, la copia sale del
 * catálogo sin borrarse: sus préstamos siguen contando en los reportes.
 */
class PrestamoInterbibliotecarioController extends Controller
{
    private const RELACIONES = [
        'ejemplar:id,libro_id,numero_copia,codigo_barras,codigo_lomo,estado_proceso,disponible,ubicacion_id',
        'ejemplar.libro:id,titulo,isbn,tipo_material_id',
        'ejemplar.libro.autores:id,nombre',
        'ejemplar.ubicacion:id,nombre',
        'registradoPor:id,nombre',
        'devueltoPor:id,nombre',
    ];

    public function __construct(private ReservaLibroService $reservas)
    {
    }

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
        ]);

        $base = PrestamoInterbibliotecario::query();
        foreach (Busqueda::palabras((string) $request->query('q')) as $palabra) {
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
     * que se crea con lo básico (título, autores, ISBN, editorial, año y tipo de material).
     * La copia nace «En estante», lista para prestar; si el título tenía lista de espera,
     * queda apartada para el primero de la fila.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'libro_id' => ['required_without:titulo', 'nullable', 'exists:libros,id'],
            'titulo' => ['required_without:libro_id', 'nullable', 'string', 'max:255'],
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

        [$prestamo, $apartada] = DB::transaction(function () use ($data, $request) {
            $staff = $request->user();
            $libro = ! empty($data['libro_id']) ? Libro::findOrFail($data['libro_id']) : $this->crearTitulo($data, $staff->id);

            $ejemplar = Ejemplar::create([
                'libro_id' => $libro->id,
                'numero_copia' => Ejemplar::where('libro_id', $libro->id)->max('numero_copia') + 1,
                'codigo_barras' => trim($data['codigo_barras']),
                'ubicacion_id' => $data['ubicacion_id'] ?? null,
                'estado_proceso' => 'en_estante',
            ]);

            $prestamo = PrestamoInterbibliotecario::create([
                'ejemplar_id' => $ejemplar->id,
                'biblioteca' => trim($data['biblioteca']),
                'fecha_ingreso' => $data['fecha_ingreso'],
                'fecha_limite' => $data['fecha_limite'],
                'nota' => $data['nota'] ?? null,
                'registrado_por_staff_id' => $staff->id,
            ]);

            // Una copia que llega al estante atiende primero a la lista de espera del título.
            return [$prestamo, $this->reservas->liberarLibro($ejemplar->refresh())];
        });

        $prestamo->load(self::RELACIONES);
        $ejemplar = $prestamo->ejemplar;
        Bitacora::registrar(
            'libros.recibir_interbibliotecario',
            "Recibió en préstamo interbibliotecario «{$ejemplar->libro->titulo}» de {$prestamo->biblioteca} (copia {$ejemplar->numero_copia}, {$ejemplar->codigo_barras}): vuelve a su biblioteca el {$prestamo->fecha_limite->format('d/m/Y')}"
                .($apartada ? ". Quedó apartada para {$apartada->usuario?->descripcion()}, primera en la lista de espera" : ''),
            entidad: $prestamo,
            datos: ['codigo_barras' => $ejemplar->codigo_barras, 'biblioteca' => $prestamo->biblioteca, 'fecha_limite' => $prestamo->fecha_limite->toDateString()],
            afectado: $apartada?->usuario,
        );

        return response()->json($this->conDetalle($prestamo, null), 201);
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
     * historial). No se puede mientras un usuario la tenga o esté apartada para alguien.
     * Si el título se queda sin otras copias que se presten, su lista de espera se cancela.
     */
    public function devolver(Request $request, PrestamoInterbibliotecario $prestamoInterbibliotecario)
    {
        $data = $request->validate([
            'nota' => ['nullable', 'string', 'max:2000'],
        ]);

        $resultado = DB::transaction(function () use ($prestamoInterbibliotecario, $request, $data) {
            $ejemplar = Ejemplar::whereKey($prestamoInterbibliotecario->ejemplar_id)->lockForUpdate()->first();
            $prestamo = PrestamoInterbibliotecario::whereKey($prestamoInterbibliotecario->id)->lockForUpdate()->first();

            if ($prestamo->devuelto_el) {
                return ['error' => "Ya se devolvió a {$prestamo->biblioteca} el {$prestamo->devuelto_el->format('d/m/Y')}."];
            }

            $activo = Prestamo::with('usuario:id,nombre,apellido,rut,tipo_documento,pais')->where('ejemplar_id', $ejemplar->id)->where('estado', 'activo')->first();
            if ($activo) {
                return ['error' => "Está prestada a {$activo->usuario?->descripcion()} hasta el {$activo->fecha_devolucion?->format('d/m/Y')}: recíbela primero en Préstamos."];
            }

            $apartada = ReservaLibro::with('usuario')->where('ejemplar_id', $ejemplar->id)->where('estado', 'pendiente')->first();
            if ($apartada) {
                return ['error' => "Está apartada para {$apartada->usuario?->descripcion()}, que la reservó: cancela esa reserva antes de devolverla."];
            }

            $staff = $request->user();
            $nota = trim((string) ($data['nota'] ?? ''));
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
                'motivo' => "Devuelto a {$prestamo->biblioteca} (préstamo interbibliotecario)",
            ]);
            $ejemplar->update(['estado_proceso' => 'devuelto_propietaria', 'estado_personalizado_id' => null, 'disponible' => false]);

            // Sin otra copia que se pueda prestar, nadie más va a recibir este título.
            $quedanCopias = Ejemplar::where('libro_id', $ejemplar->libro_id)->whereKeyNot($ejemplar->id)
                ->whereNotIn('estado_proceso', ['de_baja', 'devuelto_propietaria'])->exists();
            $enCola = $quedanCopias ? 0 : ReservaLibro::where('libro_id', $ejemplar->libro_id)->where('estado', 'en_cola')->update(['estado' => 'cancelado']);

            return ['prestamo' => $prestamo, 'en_cola' => $enCola];
        });

        if (isset($resultado['error'])) {
            return response()->json(['message' => $resultado['error']], 409);
        }

        $prestamo = $resultado['prestamo']->load(self::RELACIONES)->loadCount('prestamos as total_prestamos');
        Bitacora::registrar(
            'libros.devolver_interbibliotecario',
            "Devolvió a {$prestamo->biblioteca} «{$prestamo->ejemplar->libro->titulo}» (copia {$prestamo->ejemplar->numero_copia}, {$prestamo->ejemplar->codigo_barras}), préstamo interbibliotecario"
                .($resultado['en_cola'] ? ". {$resultado['en_cola']} ".($resultado['en_cola'] === 1 ? 'reserva en espera cancelada' : 'reservas en espera canceladas') : ''),
            entidad: $prestamo,
            datos: ['codigo_barras' => $prestamo->ejemplar->codigo_barras, 'biblioteca' => $prestamo->biblioteca, 'en_cola' => $resultado['en_cola']],
        );

        return response()->json([...$this->conDetalle($prestamo, null), 'reservas_canceladas' => $resultado['en_cola']]);
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

    /** Un título nuevo con lo básico; se completa después en «Editar ficha» si hace falta. */
    private function crearTitulo(array $data, int $staffId): Libro
    {
        $libro = Libro::create([
            'titulo' => trim($data['titulo']),
            'isbn' => isset($data['isbn']) && trim($data['isbn']) !== '' ? trim($data['isbn']) : null,
            'editorial' => $data['editorial'] ?? null,
            'anio_publicacion' => $data['anio_publicacion'] ?? null,
            'tipo_material_id' => $data['tipo_material_id'] ?? null,
            'catalogado_por_staff_id' => $staffId,
        ]);

        // En orden: el primero es el autor principal (100), como al catalogar.
        $autores = collect($data['autores'] ?? [])->map(fn ($nombre) => trim((string) $nombre))->filter()->unique()->values();
        $libro->autores()->sync($autores->mapWithKeys(fn ($nombre, $i) => [Autor::firstOrCreate(['nombre' => $nombre])->id => ['orden' => $i + 1]])->all());

        return $libro;
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
