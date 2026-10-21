<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaRecurso;
use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use App\Notifications\MultaGeneradaNotification;
use App\Rules\DiaConAtencion;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PrestamoController extends Controller
{
    public function __construct(
        private MultaService $multaService,
        private ReservaLibroService $reservaLibroService,
    ) {
    }

    /**
     * Con usuario_id (la ficha de una persona en Préstamos): todos sus préstamos, que
     * siempre son pocos. Sin usuario_id (Listado de préstamos): por páginas, con la
     * pestaña (activos, vencidos, devueltos, todos), el tipo y la búsqueda resueltos en
     * la base, y cuántos hay en cada pestaña para sus contadores.
     */
    public function index(Request $request)
    {
        $relaciones = ['usuario:id,nombre,apellido,rut', 'multas:id,prestamo_id,tipo,monto,estado'];

        if ($usuarioId = $request->query('usuario_id')) {
            $query = Prestamo::with($relaciones)->where('usuario_id', $usuarioId);

            if ($estado = $request->query('estado')) {
                $query->where('estado', $estado);
            }

            if ($tipoItem = $request->query('tipo_item')) {
                $query->where('tipo_item', $tipoItem);
            }

            return response()->json($query->latest('fecha_prestamo')->get());
        }

        $request->validate([
            'vista' => ['nullable', Rule::in(['activos', 'vencidos', 'devueltos', 'todos'])],
            'tipo' => ['nullable', Rule::in(['libros', 'recursos'])],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        // Tipo y búsqueda primero: los contadores de las pestañas los respetan.
        $base = Prestamo::query()
            ->when($request->query('tipo') === 'libros', fn ($q) => $q->where('tipo_item', 'libro'))
            ->when($request->query('tipo') === 'recursos', fn ($q) => $q->where('tipo_item', '!=', 'libro'))
            ->when(trim((string) $request->query('q')) !== '', function ($q) use ($request) {
                $busqueda = trim((string) $request->query('q'));
                $digitos = preg_replace('/[^0-9kK]/', '', $busqueda);
                // Título y código en préstamos, y nombre o RUT en usuarios: cada parte con su
                // índice de trigramas, unidas por id (revisar préstamo por préstamo era lento).
                $q->where(function ($w) use ($busqueda, $digitos) {
                    $w->whereIn('prestamos.id', DB::table('prestamos')->select('id')
                        ->whereRaw(Busqueda::sinTildes('libro_titulo').' ilike ?', [Busqueda::patron($busqueda)])
                        ->unionAll(DB::table('prestamos')->select('id')->where('codigo_barras', 'ilike', "%{$busqueda}%")))
                        ->orWhereIn('prestamos.usuario_id', DB::table('usuarios')->select('id')->where(fn ($u) => $u
                            ->whereCadaPalabra("(nombre || ' ' || apellido)", $busqueda)
                            ->orWhere('rut', 'ilike', "%{$busqueda}%")
                            ->when(strlen($digitos) >= 3, fn ($r) => $r->orWhereRaw("regexp_replace(rut, '[^0-9kK]', '', 'g') ilike ?", ["%{$digitos}%"]))));
                });
            });

        $vistas = [
            'activos' => fn ($q) => $q->where('estado', '!=', 'devuelto'),
            'vencidos' => fn ($q) => $q->vencidos(),
            'devueltos' => fn ($q) => $q->where('estado', 'devuelto'),
            'todos' => fn ($q) => $q,
        ];

        $vista = $request->query('vista', 'activos');
        $pagina = $vistas[$vista]((clone $base)->with($relaciones))
            ->latest('fecha_prestamo')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina($request, 50, 200));

        return response()->json([
            ...$pagina->toArray(),
            'conteos' => collect($vistas)->map(fn ($filtro) => $filtro(clone $base)->count()),
        ]);
    }

    public function store(Request $request)
    {
        // Tanto los libros del catálogo como los recursos de apoyo (audífonos,
        // notebooks, cargadores, bicicletas… — las categorías de Administración) se
        // prestan escaneando un código de barras real. Los libros además llevan fecha
        // de préstamo/devolución acordadas en el calendario; los recursos de apoyo no
        // tienen fecha de vencimiento fija.
        $request->merge(['tipo_item' => $request->input('tipo_item', 'libro')]);

        $data = $request->validate([
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'tipo_item' => ['required', Rule::in(['libro', ...CategoriaRecurso::where('activo', true)->pluck('clave')])],
            'codigo_barras' => ['required', 'string'],
            'fecha_prestamo' => ['required_if:tipo_item,libro', 'nullable', 'date'],
            'fecha_devolucion' => ['required_if:tipo_item,libro', 'nullable', 'date', 'after_or_equal:fecha_prestamo', new DiaConAtencion('para la devolución')],
        ], [
            'tipo_item.in' => 'Esa categoría de recurso no existe o está desactivada.',
        ]);

        $staff = $request->user();
        $esLibro = $data['tipo_item'] === 'libro';

        if ($bloqueo = $this->multaService->mensajeBloqueo($data['usuario_id'])) {
            return response()->json(['message' => $bloqueo], 409);
        }

        // Todo el ciclo lectura→decisión→escritura va en una transacción con
        // lockForUpdate(): sin esto, dos requests concurrentes sobre el mismo
        // codigo_barras pueden pasar ambas el chequeo "disponible" antes de que
        // la primera escriba, resultando en doble préstamo del mismo ejemplar/equipo.
        [$response, $status] = DB::transaction(function () use ($data, $esLibro, $staff) {
            $ejemplar = null;
            $equipo = null;
            $reservaPropia = null;

            if ($esLibro) {
                // Un usuario no puede tener dos libros prestados a la vez — debe devolver
                // el que tiene antes de llevarse otro. Solo aplica a libros: los equipos
                // (audífonos/notebook/cargador) no tienen este límite.
                $yaTieneLibro = Prestamo::where('usuario_id', $data['usuario_id'])
                    ->where('tipo_item', 'libro')
                    ->where('estado', '!=', 'devuelto')
                    ->exists();

                if ($yaTieneLibro) {
                    return [['message' => 'Este usuario ya tiene un libro prestado sin devolver — debe devolverlo antes de llevarse otro.'], 409];
                }

                // El de 14 dígitos o el de 8 de la etiqueta de lomo.
                $ejemplar = Ejemplar::porCodigo($data['codigo_barras'])->lockForUpdate()->first();

                if (! $ejemplar) {
                    return [['message' => 'Código de barras no encontrado en el sistema'], 404];
                }

                // Una copia apartada para esta misma persona (reserva lista para
                // retirar) sí se le presta: es el retiro de su reserva.
                if (! $ejemplar->disponible) {
                    $reservaPropia = ReservaLibro::where('ejemplar_id', $ejemplar->id)
                        ->where('usuario_id', $data['usuario_id'])
                        ->where('estado', 'pendiente')
                        ->lockForUpdate()
                        ->first();
                }

                if (! $ejemplar->disponible && ! $reservaPropia) {
                    return [['message' => 'Este libro ya está reservado/prestado por otra persona'], 409];
                }

                if ($ejemplar->estado_proceso !== 'en_estante') {
                    return [['message' => "Este libro no está disponible para préstamo (estado: {$ejemplar->estado_proceso})"], 409];
                }

                $ejemplar->loadMissing('libro');
            } else {
                $categoria = CategoriaRecurso::where('clave', $data['tipo_item'])->first();
                $equipo = Equipo::where('codigo_barras', $data['codigo_barras'])->where('tipo', $data['tipo_item'])->lockForUpdate()->first();

                if (! $equipo) {
                    $deOtraCategoria = Equipo::with('categoria')->where('codigo_barras', $data['codigo_barras'])->first();

                    return $deOtraCategoria
                        ? [['message' => "Ese código es de {$deOtraCategoria->categoria?->nombre} ({$deOtraCategoria->codigo_inventario}), no de {$categoria->nombre}."], 422]
                        : [['message' => 'Código de barras no encontrado en el sistema'], 404];
                }

                if (! $equipo->activo) {
                    return [['message' => 'Este recurso fue dado de baja'], 409];
                }

                if (! $equipo->disponible) {
                    return [['message' => 'Este recurso ya está prestado a otra persona'], 409];
                }

                // Algunas categorías tienen tope por persona (ej. una bicicleta a la vez). Se
                // bloquea la fila del usuario para que dos préstamos simultáneos no lo pasen.
                if ($categoria->limite_por_persona) {
                    Usuario::whereKey($data['usuario_id'])->lockForUpdate()->first();
                    $enUso = Prestamo::where('usuario_id', $data['usuario_id'])
                        ->where('tipo_item', $categoria->clave)
                        ->where('estado', '!=', 'devuelto')
                        ->count();

                    if ($enUso >= $categoria->limite_por_persona) {
                        $tiene = $enUso === 1 ? '1 '.mb_strtolower($categoria->nombre) : "{$enUso} ".mb_strtolower($categoria->nombre_plural);

                        return [['message' => "Este usuario ya tiene {$tiene} sin devolver: se presta un máximo de {$categoria->limite_por_persona} por persona a la vez."], 409];
                    }
                }
            }

            $prestamo = Prestamo::create([
                'usuario_id' => $data['usuario_id'],
                'ejemplar_id' => $ejemplar?->id,
                'equipo_id' => $equipo?->id,
                'libro_titulo' => $esLibro ? $ejemplar->tituloConCopia() : $equipo->codigo_inventario,
                'tipo_item' => $data['tipo_item'],
                'codigo_barras' => $esLibro ? $ejemplar->codigo_barras : $equipo->codigo_barras,
                'fecha_prestamo' => $esLibro ? $data['fecha_prestamo'] : now(),
                'fecha_devolucion' => $esLibro ? $data['fecha_devolucion'] : null,
                'estado' => 'activo',
                'prestado_por' => $staff->nombre,
                'prestado_por_staff_id' => $staff->id,
            ]);

            $ejemplar?->update(['disponible' => false]);
            $equipo?->update(['disponible' => false]);
            $reservaPropia?->update(['estado' => 'retirado']);
            $otraReservaCerrada = $ejemplar && $this->reservaLibroService->cerrarAlPrestarOtraCopia($data['usuario_id'], $ejemplar) > 0;

            $prestamo->load('usuario:id,nombre,apellido,rut,tipo_documento,pais');

            Bitacora::registrar(
                'prestamos.prestar',
                ($esLibro ? "Prestó «{$prestamo->libro_titulo}»" : "Prestó el recurso {$prestamo->libro_titulo}")
                    .($prestamo->fecha_devolucion ? ', devolución el '.$prestamo->fecha_devolucion->format('d/m/Y') : '')
                    .($reservaPropia ? ' (retiro de su reserva)' : '')
                    .($otraReservaCerrada ? ' (cierra la reserva que tenía de este título)' : ''),
                entidad: $prestamo,
                datos: ['codigo_barras' => $prestamo->codigo_barras, 'tipo_item' => $prestamo->tipo_item],
                afectado: $prestamo->usuario,
            );

            return [$prestamo, 201];
        });

        return response()->json($response, $status);
    }

    public function devolver(Request $request, Prestamo $prestamo)
    {
        $staff = $request->user();
        $ahora = now();

        // Ahora cada multa es una fila propia: sin este bloqueo, un doble click en
        // "Devolver" generaría dos multas por el mismo atraso.
        [$yaDevuelto, $multa] = DB::transaction(function () use ($prestamo, $staff, $ahora) {
            $bloqueado = Prestamo::whereKey($prestamo->id)->lockForUpdate()->first();
            if ($bloqueado->estado === 'devuelto') {
                return [true, null];
            }

            $prestamo->update([
                'fecha_devolucion_real' => $ahora,
                'estado' => 'devuelto',
                'devuelto_por' => $staff->nombre,
                'devuelto_por_staff_id' => $staff->id,
            ]);

            $multa = $this->multaService->generarPorAtraso($prestamo, $ahora, $staff);

            if ($prestamo->ejemplar_id) {
                // liberarLibro() no siempre deja la copia "disponible": si hay alguien en
                // la cola de espera, la promueve a 'pendiente' en vez de liberarla del todo.
                $ejemplar = Ejemplar::whereKey($prestamo->ejemplar_id)->lockForUpdate()->first();
                if ($ejemplar) {
                    $this->reservaLibroService->liberarLibro($ejemplar);
                }
            }

            if ($prestamo->equipo_id) {
                Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->first()?->update(['disponible' => true]);
            }

            $prestamo->loadMissing('usuario');
            Bitacora::registrar(
                'prestamos.devolver',
                "Recibió la devolución de «{$prestamo->libro_titulo}»",
                entidad: $prestamo,
                datos: ['codigo_barras' => $prestamo->codigo_barras],
                afectado: $prestamo->usuario,
            );

            if ($multa) {
                Bitacora::registrar(
                    'multas.generar',
                    'Se generó una multa por atraso de '.Bitacora::pesos($multa->monto)." ({$multa->dias_atraso} ".($multa->dias_atraso === 1 ? 'día' : 'días').')',
                    entidad: $multa,
                    datos: ['prestamo_id' => $prestamo->id, 'monto' => $multa->monto],
                    afectado: $prestamo->usuario,
                );
            }

            return [false, $multa];
        });

        if ($yaDevuelto) {
            return response()->json(['message' => 'Este préstamo ya fue devuelto'], 409);
        }

        // Sin SMTP configurado (ver .env.example), esto solo queda escrito en
        // storage/logs/laravel.log — no falla ni intenta salir a la red.
        if ($multa) {
            $prestamo->loadMissing('usuario');
            if ($prestamo->usuario?->email) {
                $prestamo->usuario->notify(new MultaGeneradaNotification($multa));
            }
        }

        return response()->json($prestamo->load(['usuario:id,nombre,apellido,rut', 'multas:id,prestamo_id,tipo,monto,estado']));
    }
}
