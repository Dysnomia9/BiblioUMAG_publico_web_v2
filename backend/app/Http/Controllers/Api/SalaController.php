<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use App\Services\EntradaService;
use App\Services\ReservaSalaService;
use App\Support\Bitacora;
use App\Support\Documento;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalaController extends Controller
{
    /** Pestañas del Listado de reservas. */
    private const VISTAS_LISTADO = ['activas' => 'activa', 'finalizadas' => 'finalizada', 'no_show' => 'no_show', 'canceladas' => 'cancelada', 'todas' => null];

    public function __construct(private ReservaSalaService $reservaSalaService)
    {
    }

    /**
     * Listado de reservas (Gestión › Consultas e historiales): todas las reservas de
     * salas y logias, por páginas, con la pestaña, la sala, el rango de fechas y la
     * búsqueda (sala, nombre o RUT de cualquier integrante) resueltos en la base, y
     * cuántas hay en cada pestaña para sus contadores.
     */
    public function listado(Request $request)
    {
        $request->validate([
            'vista' => ['nullable', Rule::in(array_keys(self::VISTAS_LISTADO))],
            'sala_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $this->reservaSalaService->liberarVencidas();

        $busqueda = trim((string) $request->query('q'));
        $base = Reserva::query()
            ->when($request->query('sala_id'), fn ($q, $salaId) => $q->where('sala_id', $salaId))
            ->when($request->query('desde'), fn ($q, $desde) => $q->where('fecha', '>=', $desde))
            ->when($request->query('hasta'), fn ($q, $hasta) => $q->where('fecha', '<=', $hasta))
            ->when($busqueda !== '', function ($q) use ($busqueda) {
                $documento = Documento::normalizar($busqueda);
                $q->where(fn ($w) => $w
                    ->whereHas('sala', fn ($s) => $s->whereSinTildes('nombre', $busqueda))
                    ->orWhereHas('participantes', fn ($u) => $u->where(fn ($p) => $p
                        ->whereCadaPalabra("(usuarios.nombre || ' ' || usuarios.apellido)", $busqueda)
                        ->orWhere('usuarios.rut', 'ilike', "%{$busqueda}%")
                        // RUT sin puntos ni guion, pasaporte o DNI.
                        ->when(strlen($documento) >= 3, fn ($r) => $r->orWhereRaw(Documento::sql('usuarios.rut').' like ?', ["%{$documento}%"])))));
            });

        $filtro = fn (string $vista) => fn ($q) => self::VISTAS_LISTADO[$vista] ? $q->where('estado', self::VISTAS_LISTADO[$vista]) : $q;
        $vista = $request->query('vista', 'activas');
        // Las activas, de la más próxima a la más lejana; el resto, de la más reciente hacia atrás.
        $orden = $vista === 'activas' ? 'asc' : 'desc';

        $pagina = $filtro($vista)((clone $base)->with(['sala:id,nombre,tipo', 'participantes:id,nombre,apellido,rut']))
            ->orderBy('fecha', $orden)
            ->orderBy('hora_inicio', $orden)
            ->orderBy('id', $orden)
            ->paginate(Paginacion::porPagina($request, 50, 200));

        return response()->json([
            ...$pagina->toArray(),
            'data' => collect($pagina->items())->map(fn (Reserva $r) => ReservaSalaService::paraListado($r))->all(),
            'conteos' => collect(self::VISTAS_LISTADO)->map(fn ($estado, $v) => $filtro($v)(clone $base)->count()),
            'salas' => Sala::orderBy('id')->get(['id', 'nombre', 'tipo']),
        ]);
    }

    /**
     * Desde el Listado de reservas, quien tiene el permiso de editarlas cambia la sala,
     * el día o el horario de una reserva activa (los integrantes se corrigen con
     * actualizarIntegrantes()). Mismas reglas que al reservar: horario de atención,
     * duración y grilla de 30 minutos, sin chocar con otra reserva de esa sala, sin que
     * un integrante quede en dos salas a la vez y dentro de la cuota diaria. Las
     * finalizadas, canceladas o en que no se presentaron quedan como registro.
     */
    public function editarReserva(Request $request, Reserva $reserva)
    {
        $data = $request->validate([
            'sala_id' => ['required', 'integer', 'exists:salas,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
        ]);

        if ($reserva->estado !== 'activa') {
            return response()->json(['message' => 'Solo se editan las reservas activas: las finalizadas, canceladas o en que no se presentaron quedan como registro.'], 409);
        }

        if ($this->reservaSalaService->liberarSiVencida($reserva)) {
            return response()->json(['message' => 'El plazo de 15 minutos para confirmar esta reserva ya venció — la sala quedó liberada'], 409);
        }

        $mismoDia = $data['fecha'] === $reserva->fecha->toDateString();
        if ($reserva->hora_prestamo_real && ! $mismoDia) {
            return response()->json(['message' => 'El grupo ya llegó a esta reserva: se puede cambiar de sala o de horario, pero no pasarla a otro día.'], 409);
        }

        $permitirHoraPasada = $request->user()->puede('salas.hora_pasada');
        if (! $mismoDia && ! $permitirHoraPasada && $data['fecha'] < now()->toDateString()) {
            return response()->json(['message' => 'Ese día ya pasó: elige hoy o un día próximo.'], 422);
        }

        $error = $this->reservaSalaService->validarTramo($data, false, $permitirHoraPasada, $reserva);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        if ($this->reservaSalaService->existeSolapamiento($data['sala_id'], $data['fecha'], $data['hora_inicio'], $data['hora_fin'], $reserva->id)) {
            return response()->json(['message' => 'Ese tramo ya se encuentra reservado en esa sala'], 409);
        }

        $ruts = $reserva->participantes()->pluck('rut')->all();

        $rutConflicto = $this->reservaSalaService->participanteConReservaSolapada($ruts, $data['fecha'], $data['hora_inicio'], $data['hora_fin'], $reserva->id);
        if ($rutConflicto) {
            return response()->json(['message' => "El RUT {$rutConflicto} ya tiene otra sala reservada en ese horario"], 409);
        }

        $excedeCuota = $this->reservaSalaService->participanteExcedeCuotaDiaria($ruts, $data['fecha'], $data['hora_inicio'], $data['hora_fin'], $reserva->id);
        if ($excedeCuota) {
            return response()->json(['message' => $this->reservaSalaService->mensajeCuotaExcedida($excedeCuota)], 409);
        }

        $resumen = fn (Reserva $r) => [
            'sala' => $r->sala->nombre,
            'fecha' => $r->fecha->format('d/m/Y'),
            'horario' => substr($r->hora_inicio, 0, 5).' a '.substr($r->hora_fin, 0, 5),
        ];
        $antes = $resumen($reserva);
        $descripcionAntes = $reserva->descripcion();

        $reserva->update(Arr::only($data, ['sala_id', 'fecha', 'hora_inicio', 'hora_fin']));
        $reserva->load(['sala:id,nombre,tipo', 'participantes:id,nombre,apellido,rut']);

        if ($cambios = Bitacora::cambios($antes, $resumen($reserva))) {
            Bitacora::registrar(
                'salas.editar',
                "Cambió la reserva de {$descripcionAntes} a {$reserva->descripcion()}",
                entidad: $reserva,
                datos: $cambios,
                afectado: $reserva->usuario,
            );
        }

        return response()->json(ReservaSalaService::paraListado($reserva));
    }

    public function index(Request $request)
    {
        $fecha = $request->query('fecha', now()->toDateString());
        $salas = Sala::orderBy('id')->get();
        [$apertura, $cierre] = $this->reservaSalaService->rangoDeLaSemana();
        $atencion = $this->reservaSalaService->ventanaDelDia(Carbon::parse($fecha));

        return response()->json([
            'fecha' => $fecha,
            'apertura' => $apertura,
            'cierre' => $cierre,
            'atencion' => $atencion,
            'granularidad' => config('salas.granularidad'),
            'duracion_minima' => config('salas.duracion_minima'),
            'duracion_maxima' => config('salas.duracion_maxima'),
            'cuota_diaria' => config('salas.cuota_diaria'),
            'cerrado' => $atencion === null,
            'salas' => $this->reservaSalaService->vistaDelDia($salas, $fecha),
        ]);
    }

    public function storeReserva(Request $request)
    {
        $data = $request->validate([
            'sala_id' => ['required', 'exists:salas,id'],
            'fecha' => ['required', 'date'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'cantidad_personas' => ['required', 'integer', 'min:2', 'max:5'],
            'inmediata' => ['sometimes', 'boolean'],
            'ruts' => ['required', 'array'],
            'ruts.*' => ['required', 'string'],
        ]);

        // Los RUT (o pasaportes/DNI de estudiantes extranjeros) deben pertenecer a
        // usuarios ya registrados: no se admiten visitantes externos en la reserva de
        // logias (mismo criterio que el portal en PortalController::reservarSala).
        $data['ruts'] = $this->reservaSalaService->identificadoresDeParticipantes($data['ruts']);

        if (count($data['ruts']) !== $data['cantidad_personas']) {
            return response()->json(['message' => 'Debe ingresar un RUT por cada persona indicada'], 422);
        }

        $inmediata = $data['inmediata'] ?? false;
        // Agendar en una hora que ya pasó hoy (ej. registrar algo que se atendió sin
        // pasar por el sistema en su momento) requiere un permiso aparte — el resto del
        // personal sigue restringido a "desde ahora en adelante", igual que el portal.
        $permitirHoraPasada = $request->user()->puede('salas.hora_pasada');
        $error = $this->reservaSalaService->validarTramo($data, $inmediata, $permitirHoraPasada);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $existe = $this->reservaSalaService->existeSolapamiento(
            $data['sala_id'],
            $data['fecha'],
            $data['hora_inicio'],
            $data['hora_fin'],
        );

        if ($existe) {
            return response()->json(['message' => 'Ese tramo ya se encuentra reservado'], 409);
        }

        if ($pendiente = $this->reservaSalaService->participanteConReservaPendiente($data['ruts'])) {
            return response()->json(['message' => $this->reservaSalaService->mensajeReservaPendiente($pendiente)], 409);
        }

        $rutConflicto = $this->reservaSalaService->participanteConReservaSolapada(
            $data['ruts'],
            $data['fecha'],
            $data['hora_inicio'],
            $data['hora_fin'],
        );

        if ($rutConflicto) {
            return response()->json(['message' => "El RUT {$rutConflicto} ya tiene otra sala reservada en ese horario"], 409);
        }

        $excedeCuota = $this->reservaSalaService->participanteExcedeCuotaDiaria(
            $data['ruts'],
            $data['fecha'],
            $data['hora_inicio'],
            $data['hora_fin'],
        );

        if ($excedeCuota) {
            return response()->json(['message' => $this->reservaSalaService->mensajeCuotaExcedida($excedeCuota)], 409);
        }

        $usuarios = Usuario::whereIn('rut', $data['ruts'])->get()->keyBy('rut');

        $reserva = Reserva::create([
            'sala_id' => $data['sala_id'],
            'usuario_id' => $usuarios[$data['ruts'][0]]->id,
            'rut_usuario' => $data['ruts'][0],
            'cantidad_personas' => $data['cantidad_personas'],
            'fecha' => $data['fecha'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'estado' => 'activa',
        ]);

        $reserva->participantes()->attach($usuarios->pluck('id'));

        Bitacora::registrar(
            'salas.reservar',
            "Reservó {$reserva->descripcion()} para {$reserva->cantidad_personas} personas",
            entidad: $reserva,
            datos: ['integrantes' => $data['ruts']],
            afectado: $usuarios[$data['ruts'][0]],
        );

        return response()->json($reserva, 201);
    }

    /**
     * Corrige quiénes vienen en una reserva activa, típicamente al confirmar la llegada:
     * alguien no vino, se sumó otra persona o un RUT quedó mal escrito. Mismas reglas que
     * al reservar: de 2 a 5 usuarios registrados, sin otra sala a esa hora y dentro de la
     * cuota diaria. Si la llegada ya estaba confirmada, quien se suma queda presente en
     * la biblioteca, igual que el resto del grupo.
     */
    public function actualizarIntegrantes(Request $request, Reserva $reserva, EntradaService $entradas)
    {
        $data = $request->validate([
            'ruts' => ['required', 'array', 'min:2', 'max:5'],
            'ruts.*' => ['required', 'string'],
        ], [
            'ruts.min' => 'La reserva necesita al menos 2 personas.',
            'ruts.max' => 'La reserva admite como máximo 5 personas.',
            'ruts.*.required' => 'Complete el RUT (o pasaporte/DNI) de cada persona.',
        ]);

        if ($reserva->estado !== 'activa' || $reserva->hora_devolucion_real) {
            return response()->json(['message' => 'Esta reserva ya no está activa (fue cancelada, finalizada, o no se presentaron a tiempo)'], 409);
        }

        if ($this->reservaSalaService->liberarSiVencida($reserva)) {
            return response()->json(['message' => 'El plazo de 15 minutos para confirmar esta reserva ya venció — la sala quedó liberada'], 409);
        }

        $ruts = $this->reservaSalaService->identificadoresDeParticipantes($data['ruts']);
        $fecha = $reserva->fecha->toDateString();

        // Mientras el grupo no llega, esta reserva es la pendiente de cada integrante:
        // quien se suma no puede tener ya otra.
        $quienesSeSuman = array_values(array_diff($ruts, $reserva->participantes()->pluck('rut')->all()));
        if (! $reserva->hora_prestamo_real && $quienesSeSuman
            && $pendiente = $this->reservaSalaService->participanteConReservaPendiente($quienesSeSuman, $reserva->id)) {
            return response()->json(['message' => $this->reservaSalaService->mensajeReservaPendiente($pendiente)], 409);
        }

        $rutConflicto = $this->reservaSalaService->participanteConReservaSolapada($ruts, $fecha, $reserva->hora_inicio, $reserva->hora_fin, $reserva->id);
        if ($rutConflicto) {
            return response()->json(['message' => "El RUT {$rutConflicto} ya tiene otra sala reservada en ese horario"], 409);
        }

        $excedeCuota = $this->reservaSalaService->participanteExcedeCuotaDiaria($ruts, $fecha, $reserva->hora_inicio, $reserva->hora_fin, $reserva->id);
        if ($excedeCuota) {
            return response()->json(['message' => $this->reservaSalaService->mensajeCuotaExcedida($excedeCuota)], 409);
        }

        $antes = $reserva->participantes()->get();
        $despues = Usuario::whereIn('rut', $ruts)->get()->sortBy(fn (Usuario $u) => array_search($u->rut, $ruts, true))->values();
        $agregados = $despues->whereNotIn('id', $antes->pluck('id'));
        $quitados = $antes->whereNotIn('id', $despues->pluck('id'));

        if ($agregados->isNotEmpty() || $quitados->isNotEmpty()) {
            // Quien reservó sigue a cargo mientras siga en el grupo; si se fue, lo
            // reemplaza el primero de la lista.
            $titular = $despues->firstWhere('id', $reserva->usuario_id) ?? $despues->first();

            DB::transaction(function () use ($reserva, $despues, $titular) {
                $reserva->update([
                    'usuario_id' => $titular->id,
                    'rut_usuario' => $titular->rut,
                    'cantidad_personas' => $despues->count(),
                ]);
                $reserva->participantes()->sync($despues->pluck('id'));
            });

            Bitacora::registrar(
                'salas.integrantes',
                "Modificó los integrantes de {$reserva->descripcion()}",
                entidad: $reserva,
                datos: array_filter([
                    'agregados' => $agregados->map->descripcion()->join(', '),
                    'quitados' => $quitados->map->descripcion()->join(', '),
                ]),
                afectado: $titular,
            );
        }

        $reserva->load('participantes');

        return response()->json([
            'reserva_id' => $reserva->id,
            'cantidad_personas' => $reserva->cantidad_personas,
            'personas' => ReservaSalaService::personas($reserva),
            // Con la llegada ya confirmada, quien se suma también queda presente.
            'asistencia' => $reserva->hora_prestamo_real && $agregados->isNotEmpty()
                ? $entradas->marcarPresentesPorReserva($reserva, $request->user())
                : null,
        ]);
    }

    /**
     * Cancela la reserva sin borrar la fila (soft delete vía estado, no
     * DB::delete()) — así queda historial de qué se canceló y cuándo, en vez de
     * desaparecer sin dejar rastro. El resto del sistema (existeSolapamiento(),
     * vistaDelDia(), etc.) ya excluye 'cancelada' de todo lo que importa: no
     * bloquea el tramo, no cuenta para la cuota diaria, no aparece en la línea de
     * tiempo.
     */
    public function destroyReserva(Reserva $reserva)
    {
        $estadoAnterior = $reserva->estado;
        $reserva->update(['estado' => 'cancelada']);

        Bitacora::registrar(
            'salas.cancelar',
            "Canceló la reserva de {$reserva->descripcion()}",
            entidad: $reserva,
            datos: ['estado' => [$estadoAnterior, 'cancelada']],
            afectado: $reserva->usuario,
        );

        return response()->json(null, 204);
    }

    /**
     * Confirmación manual de devolución de llave (sin escaneo de código de barras) —
     * a diferencia de destroyReserva(), no borra la reserva: deja registrado quién y
     * cuándo se devolvió, igual que hace escanearLogia() por la vía del código de barras.
     */
    public function devolverReserva(Request $request, Reserva $reserva)
    {
        try {
            $reserva = $this->reservaSalaService->registrarDevolucion($reserva, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        Bitacora::registrar('salas.devolucion', "Recibió la llave de {$reserva->descripcion()}", entidad: $reserva, afectado: $reserva->usuario);

        return response()->json($reserva);
    }

    /**
     * Confirmación manual de llegada (sin escaneo de código de barras) — sirve para
     * cualquier sala, incluidas las que no tienen codigo_barras (Seminarios, Postgrado,
     * AGACI). Es el "menú de confirmación": el staff ve el listado de reservas del día
     * y marca aquí quién sí se presentó dentro del plazo de 15 minutos.
     *
     * Con la llegada, cada integrante queda presente en la biblioteca: a quien no
     * marcó entrada hoy se le registra, a quien ya la tenía no se le duplica. La
     * respuesta trae `asistencia` con ambos grupos para avisarle al personal.
     */
    public function confirmarLlegada(Request $request, Reserva $reserva, EntradaService $entradas)
    {
        try {
            $reserva = $this->reservaSalaService->registrarLlegada($reserva, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($this->conAsistencia($reserva, $request, $entradas, 'Confirmó la llegada a'));
    }

    private function conAsistencia(Reserva $reserva, Request $request, EntradaService $entradas, string $accion): Reserva
    {
        $asistencia = $entradas->marcarPresentesPorReserva($reserva, $request->user());

        Bitacora::registrar(
            'salas.llegada',
            "{$accion} {$reserva->descripcion()}",
            entidad: $reserva,
            datos: $asistencia,
            afectado: $reserva->usuario,
        );

        return $reserva->setAttribute('asistencia', $asistencia);
    }

    /** Libera de inmediato una reserva vencida sin confirmar, sin esperar a un nuevo intento de reserva sobre el mismo bloque. */
    public function liberarReserva(Reserva $reserva)
    {
        try {
            $reserva = $this->reservaSalaService->liberarPorNoPresentacion($reserva);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        Bitacora::registrar('salas.liberar', "Liberó por no presentación {$reserva->descripcion()}", entidad: $reserva, afectado: $reserva->usuario);

        return response()->json($reserva);
    }

    /** El primer escaneo es la entrega (la llegada del grupo, con su asistencia); el segundo, la devolución. */
    public function scanLogia(Request $request, EntradaService $entradas)
    {
        $data = $request->validate([
            'codigo_barras' => ['required', 'string'],
        ]);

        try {
            $reserva = $this->reservaSalaService->escanearLogia($data['codigo_barras'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($reserva->hora_devolucion_real) {
            Bitacora::registrar('salas.devolucion', "Recibió la llave de {$reserva->descripcion()} (código de barras)", entidad: $reserva, afectado: $reserva->usuario);
        } else {
            $reserva = $this->conAsistencia($reserva, $request, $entradas, 'Entregó con código de barras');
        }

        return response()->json($reserva->load('sala'));
    }
}
