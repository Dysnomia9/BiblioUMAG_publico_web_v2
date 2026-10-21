<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\CodigoAcceso;
use App\Models\Entrada;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Sala;
use App\Models\Usuario;
use App\Services\EntradaService;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use App\Services\ReservaSalaService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\Documento;
use App\Support\FichaBibliografica;
use App\Support\HorarioBiblioteca;
use App\Support\OpcionesCatalogo;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortalController extends Controller
{
    public function __construct(private ReservaSalaService $reservaSalaService)
    {
    }

    public function estado(Request $request)
    {
        $hoy = Carbon::today();

        return response()->json([
            'usuario' => $request->user(),
            // Horizon (el sistema legado) no distingue "quién sigue adentro": cada ingreso
            // se cierra en el mismo instante en que se registra (ver registrarEntrada()).
            // "Personas en sala" es, en la práctica, el total de ingresos del día.
            'personasEnSala' => Entrada::whereDate('fecha_hora_entrada', $hoy)->count(),
            'capacidad' => config('biblioteca.aforo'),
            'horario' => HorarioBiblioteca::semana(),
        ]);
    }

    /**
     * Autoservicio de la Constancia de No Multa (mismo dato que
     * UsuarioController::porRut(), pero acotado al propio usuario autenticado del
     * portal — no recibe RUT por parámetro para no poder consultar la deuda de otra
     * persona).
     */
    /**
     * Resumen para el inicio del portal: si ya marcó su ingreso hoy, lo que tiene
     * prestado, sus reservas vigentes y sus multas. Solo datos del propio usuario.
     */
    public function actividad(Request $request, MultaService $multaService, ReservaLibroService $reservaLibroService)
    {
        $usuario = $request->user();
        $hoy = now()->toDateString();

        $ultimaEntrada = Entrada::where('usuario_id', $usuario->id)
            ->whereDate('fecha_hora_entrada', $hoy)
            ->max('fecha_hora_entrada');

        $prestamos = $usuario->prestamos()
            ->where('estado', '!=', 'devuelto')
            ->orderByRaw('fecha_devolucion IS NULL, fecha_devolucion')
            ->get()
            ->map(fn (Prestamo $prestamo) => [
                'id' => $prestamo->id,
                'titulo' => $prestamo->libro_titulo,
                'tipo_item' => $prestamo->tipo_item,
                'fecha_devolucion' => $prestamo->fecha_devolucion?->toDateString(),
            ]);

        $reservasLibro = ReservaLibro::with('libro:id,titulo')
            ->where('usuario_id', $usuario->id)
            ->whereIn('estado', ['pendiente', 'en_cola'])
            ->orderBy('created_at')
            ->get();
        $reservaLibroService->enriquecerColaLibro($reservasLibro);

        $reservasSala = Reserva::with('sala:id,nombre')
            ->whereHas('participantes', fn ($q) => $q->where('usuarios.id', $usuario->id))
            ->where('fecha', $hoy)
            ->where('estado', 'activa')
            ->where('hora_fin', '>', now()->format('H:i:s'))
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn (Reserva $reserva) => [
                'id' => $reserva->id,
                'sala' => $reserva->sala?->nombre,
                'hora_inicio' => substr($reserva->hora_inicio, 0, 5),
                'hora_fin' => substr($reserva->hora_fin, 0, 5),
            ]);

        return response()->json([
            'entrada_hoy' => $ultimaEntrada ? Carbon::parse($ultimaEntrada)->format('H:i') : null,
            'prestamos' => $prestamos,
            'reservas_libro' => $reservasLibro->map(fn (ReservaLibro $reserva) => [
                'id' => $reserva->id,
                'titulo' => $reserva->libro?->titulo,
                'estado' => $reserva->estado,
                'fecha_retiro' => $reserva->fecha_retiro?->toDateString(),
                'posicion' => $reserva->posicion,
            ]),
            'reservas_sala' => $reservasSala,
            'multas' => $multaService->resumenPendiente($usuario),
        ]);
    }

    /** Historial de ingresos de la propia persona (los últimos 60) y cuántos lleva este mes. */
    public function ingresos(Request $request)
    {
        $usuarioId = $request->user()->id;

        $entradas = Entrada::where('usuario_id', $usuarioId)
            ->latest('fecha_hora_entrada')
            ->limit(60)
            ->get(['id', 'fecha_hora_entrada', 'via', 'registrado_por_staff_id']);

        return response()->json([
            'este_mes' => Entrada::where('usuario_id', $usuarioId)
                ->where('fecha_hora_entrada', '>=', now()->startOfMonth())
                ->count(),
            'entradas' => $entradas->map(fn (Entrada $entrada) => [
                'id' => $entrada->id,
                'fecha' => $entrada->fecha_hora_entrada->toDateString(),
                'hora' => $entrada->fecha_hora_entrada->format('H:i'),
                // qr: escaneó el código; rut: marcó con su RUT en el portal; meson: la registró el personal.
                'origen' => $entrada->via === 'qr' ? 'qr' : ($entrada->registrado_por_staff_id ? 'meson' : 'rut'),
            ]),
        ]);
    }

    public function misMultas(Request $request, MultaService $multaService)
    {
        $usuario = $request->user();

        $usuario->setAttribute('multas_pendientes', $multaService->resumenPendiente($usuario));
        $usuario->setAttribute('multas', $usuario->multas()
            ->pendientes()
            ->latest()
            ->get(['id', 'tipo', 'monto', 'detalle', 'created_at']));

        return response()->json($usuario);
    }

    public function registrarEntrada(Request $request, EntradaService $entradas)
    {
        $data = $request->validate([
            'rut' => ['sometimes', 'string'],
            'codigo' => ['required_if:via,qr', 'string'],
            'via' => ['required', 'in:manual,qr'],
        ]);

        $usuario = $request->user();

        // RUT, pasaporte o DNI: se compara sin puntos, guiones ni espacios.
        if ($data['via'] === 'manual' && Documento::normalizar($data['rut'] ?? '') !== Documento::normalizar($usuario->rut)) {
            return response()->json(['message' => 'El RUT o documento ingresado no coincide con tu cuenta'], 422);
        }

        if ($data['via'] === 'qr' && $data['codigo'] !== CodigoAcceso::vigente()->codigo) {
            return response()->json(['message' => 'El código QR no es válido. Pide al personal que lo actualice.'], 422);
        }

        // Con el RUT desde el portal queda como 'portal' (no 'manual'), para que en
        // Entradas se distinga de lo que registra el personal en el mesón. Una persona
        // queda presente una sola vez por día (ver EntradaService).
        [$entrada, $creada] = $entradas->registrarUsuario($usuario, [
            'via' => $data['via'] === 'qr' ? 'qr' : 'portal',
        ]);

        if (! $creada) {
            return response()->json([
                'message' => 'Ya marcaste tu entrada hoy a las '.$entrada->fecha_hora_entrada->format('H:i').'. Solo se registra una vez por día.',
                'entrada' => $entrada,
                'ya_registrada' => true,
            ], 409);
        }

        Bitacora::registrar(
            'entradas.registrar',
            $entrada->via === 'qr' ? 'Marcó su entrada con el código QR' : 'Marcó su entrada desde el portal',
            entidad: $entrada,
            afectado: $usuario,
        );

        return response()->json([
            'entrada' => $entrada,
            'usuario' => $usuario,
        ], 201);
    }

    public function catalogo(Request $request)
    {
        // Solo obras con al menos un ejemplar en estante (aunque esté prestado ahora
        // mismo — el usuario puede reservarlo/unirse a la cola desde el portal).
        // «Solo disponibles» y el área se filtran acá, no en el navegador: el catálogo va
        // por páginas. Con «solo disponibles» basta una condición: una copia disponible en
        // estante ya es una copia en estante.
        $soloDisponibles = filter_var($request->query('disponibles'), FILTER_VALIDATE_BOOLEAN);
        $query = Libro::query()
            ->select('libros.id')
            ->whereHas('ejemplares', fn ($q) => $q->where('estado_proceso', 'en_estante')->when($soloDisponibles, fn ($q) => $q->where('disponible', true)));

        if ($categoriaId = $request->query('categoria_id')) {
            $query->whereHas('categorias', fn ($q) => $q->where('categorias.id', $categoriaId));
        }

        // Cada palabra tiene que aparecer en algún campo, en cualquier orden (ver Busqueda::palabras()):
        // título, autor, área o ISBN (sin guiones, para que "9789567189501" encuentre
        // "978-956-7189-50-1"). Ver BusquedaLibros.
        foreach (Busqueda::palabras((string) $request->query('q')) as $palabra) {
            $query->whereIn('libros.id', BusquedaLibros::ids($palabra, BusquedaLibros::CAMPOS_PORTAL));
        }

        // Primero los títulos de la página (solo los ids: ordenarlos es barato) y después,
        // solo para esos, las copias y la próxima devolución. Calcularlo todo en la misma
        // consulta obligaba a MySQL a hacerlo para el catálogo entero antes de cortar la
        // página. Por páginas: el teléfono pide más al llegar al final de la lista.
        $pagina = $query->orderBy('titulo')->orderBy('id')->paginate(Paginacion::porPagina($request, 24, 100));
        $ids = $pagina->getCollection()->pluck('id');

        $libros = Libro::whereIn('id', $ids)
            ->with(['autores', 'categorias', 'tipoMaterial'])
            ->withCount([
                'ejemplares as ejemplares_total' => fn ($q) => $q->where('estado_proceso', 'en_estante'),
                'ejemplares as ejemplares_disponibles' => fn ($q) => $q->where('estado_proceso', 'en_estante')->where('disponible', true),
            ])
            // Fecha de devolución más próxima entre las copias prestadas ("vuelve aprox. el...").
            ->addSelect(['proxima_devolucion' => Prestamo::query()
                ->selectRaw('MIN(prestamos.fecha_devolucion)')
                ->join('ejemplares', 'ejemplares.id', '=', 'prestamos.ejemplar_id')
                ->whereColumn('ejemplares.libro_id', 'libros.id')
                ->where('prestamos.estado', '!=', 'devuelto'),
            ])
            ->get()
            ->keyBy('id');

        $pagina->setCollection($ids->map(fn ($id) => $libros[$id]));

        // Solo lo que la biblioteca definió como público para cada tipo de material:
        // los campos de uso interno (notas, catalogador, procedencia...) no salen de acá.
        return response()->json($pagina->through(fn (Libro $libro) => [
            'id' => $libro->id,
            'titulo' => $libro->titulo,
            'tipo_material' => $libro->tipoMaterial?->nombre,
            'autores' => $libro->autores->map->only(['id', 'nombre'])->values(),
            'categorias' => $libro->categorias->map->only(['id', 'nombre'])->values(),
            'ejemplares_total' => $libro->ejemplares_total,
            'ejemplares_disponibles' => $libro->ejemplares_disponibles,
            'proxima_devolucion' => $libro->proxima_devolucion ? Carbon::parse($libro->proxima_devolucion)->toDateString() : null,
            'ficha' => FichaBibliografica::ficha($libro),
        ]));
    }

    /**
     * Áreas (categorías) con al menos un título en estante, para el filtro del catálogo:
     * las que coinciden con lo escrito o, sin escribir, las de más títulos (ver
     * OpcionesCatalogo). Con el catálogo real son miles: todas juntas no servían.
     */
    public function areasCatalogo(Request $request)
    {
        return response()->json(OpcionesCatalogo::buscar(
            Categoria::class,
            $request->query('q'),
            fn ($libros) => $libros->whereHas('ejemplares', fn ($q) => $q->where('estado_proceso', 'en_estante')),
        ));
    }

    public function salas(Request $request)
    {
        $fecha = $request->query('fecha', now()->toDateString());
        $salas = Sala::orderBy('id')->get();
        $usuarioId = (int) $request->user()->id;

        // De las reservas ajenas el usuario solo ve el horario: ni nombres ni RUT
        // de los integrantes, ni quién del personal entregó o recibió la llave.
        // Es «suya» la que hizo y también aquella en que la incluyeron como integrante:
        // así puede verla y cancelarla aunque la haya hecho otra persona.
        $rutPropio = $request->user()->rut;
        $vista = $this->reservaSalaService->vistaDelDia($salas, $fecha)->map(function (array $sala) use ($usuarioId, $rutPropio) {
            $sala['tramos'] = $sala['tramos']->map(fn (array $tramo) => (int) $tramo['usuario_id'] === $usuarioId || in_array($rutPropio, array_column($tramo['personas'], 'rut'), true)
                ? ['es_mia' => true] + $tramo
                : ['usuario_id' => null, 'es_mia' => false] + Arr::except($tramo, ['personas', 'rut_usuario', 'usuario_id', 'prestado_por', 'devuelto_por'])
            )->values();

            return $sala;
        });

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
            'salas' => $vista,
            // Sus cancelaciones de hoy: cuántas le quedan sin espera y, si está esperando,
            // el aviso y hasta cuándo (para avisarle antes de cancelar y apagar la reserva).
            'cancelaciones' => [
                'sin_espera_restantes' => $this->reservaSalaService->cancelacionesSinEsperaRestantes($request->user()),
                'minutos_espera' => (int) config('salas.espera_tras_cancelar'),
                'espera' => ($hasta = $this->reservaSalaService->esperaParaReservar($request->user())) ? $this->reservaSalaService->mensajeEspera($hasta) : null,
                'espera_hasta' => $hasta?->toIso8601String(),
            ],
        ]);
    }

    public function reservarSala(Request $request)
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

        // Canceló varias reservas suyas hoy: unos minutos sin lanzar otra (config/salas.php).
        // Que otros lo incluyan como integrante en sus reservas sigue permitido.
        if ($hasta = $this->reservaSalaService->esperaParaReservar($request->user())) {
            return response()->json(['message' => $this->reservaSalaService->mensajeEspera($hasta)], 429);
        }

        // A diferencia del registro de entrada externo, aquí los RUT (o pasaportes/DNI)
        // deben pertenecer a usuarios ya registrados: no se admiten visitantes externos.
        $data['ruts'] = $this->reservaSalaService->identificadoresDeParticipantes($data['ruts']);

        // Los alumnos solo pueden reservar para el día de hoy — a diferencia del staff
        // (SalaController::storeReserva), que sí puede reservar con anticipación.
        if ($data['fecha'] !== now()->toDateString()) {
            return response()->json(['message' => 'Solo puedes reservar una sala para el día de hoy'], 422);
        }

        if (count($data['ruts']) !== $data['cantidad_personas']) {
            return response()->json(['message' => 'Debe ingresar un RUT por cada persona indicada'], 422);
        }

        $usuario = $request->user();

        // Quien reserva va en el grupo: si no, podría tomar salas a nombre de otros sin
        // que le pesen la reserva pendiente única ni la cuota diaria, y dejarlos sin
        // poder reservar. Queda primero, como la persona a cargo.
        if (! in_array($usuario->rut, $data['ruts'], true)) {
            return response()->json(['message' => 'Debes incluir tu propio RUT entre los integrantes de la reserva.'], 422);
        }
        $data['ruts'] = [$usuario->rut, ...array_values(array_diff($data['ruts'], [$usuario->rut]))];

        $inmediata = $data['inmediata'] ?? false;
        $error = $this->reservaSalaService->validarTramo($data, $inmediata);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $integrantes = Usuario::whereIn('rut', $data['ruts'])->pluck('id');

        return $this->reservaSalaService->conSalaEIntegrantesBloqueados($data['sala_id'], $integrantes, function () use ($data, $usuario, $integrantes) {
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
                return response()->json(['message' => $this->reservaSalaService->mensajeReservaPendiente($pendiente, $usuario->rut, detalleAjeno: false)], 409);
            }

            $rutConflicto = $this->reservaSalaService->participanteConReservaSolapada(
                $data['ruts'],
                $data['fecha'],
                $data['hora_inicio'],
                $data['hora_fin'],
            );

            if ($rutConflicto) {
                $mensaje = $rutConflicto === $usuario->rut
                    ? 'Ya tienes otra sala reservada en ese horario'
                    : "El RUT {$rutConflicto} ya tiene otra sala reservada en ese horario";

                return response()->json(['message' => $mensaje], 409);
            }

            $excedeCuota = $this->reservaSalaService->participanteExcedeCuotaDiaria(
                $data['ruts'],
                $data['fecha'],
                $data['hora_inicio'],
                $data['hora_fin'],
            );

            if ($excedeCuota) {
                return response()->json(['message' => $this->reservaSalaService->mensajeCuotaExcedida($excedeCuota, $usuario->rut)], 409);
            }

            $reserva = Reserva::create([
                'sala_id' => $data['sala_id'],
                'usuario_id' => $usuario->id,
                'rut_usuario' => $data['ruts'][0],
                'cantidad_personas' => $data['cantidad_personas'],
                'fecha' => $data['fecha'],
                'hora_inicio' => $data['hora_inicio'],
                'hora_fin' => $data['hora_fin'],
                'estado' => 'activa',
            ]);

            $reserva->participantes()->attach($integrantes);

            Bitacora::registrar(
                'salas.reservar',
                "Reservó desde el portal {$reserva->descripcion()} para {$reserva->cantidad_personas} personas",
                entidad: $reserva,
                datos: ['integrantes' => $data['ruts']],
                afectado: $usuario,
            );

            return response()->json($reserva, 201);
        });
    }

    /**
     * Soft delete vía estado — ver SalaController::destroyReserva() para el detalle.
     * Solo la cancela quien la hizo; un integrante se sale de ella (salirDeReservaSala()).
     */
    public function cancelarReservaSala(Request $request, Reserva $reserva)
    {
        $usuario = $request->user();

        if ($reserva->usuario_id !== $usuario->id) {
            return response()->json(['message' => $reserva->participantes()->whereKey($usuario->id)->exists()
                ? 'Esta reserva la hizo otra persona: puedes salirte de ella, pero solo quien la hizo la cancela.'
                : 'Solo puedes cancelar reservas en las que participas'], 403);
        }

        if ($motivo = $this->motivoParaNoCambiarReserva($reserva, 'no se puede cancelar')) {
            return response()->json(['message' => $motivo], 409);
        }

        $reserva->update(['estado' => 'cancelada']);
        $this->reservaSalaService->registrarCancelacionDelPortal($usuario);

        Bitacora::registrar('salas.cancelar', "Canceló desde el portal su reserva de {$reserva->descripcion()}", entidad: $reserva, afectado: $request->user());

        return response()->json(null, 204);
    }

    /**
     * Un integrante (quizás lo pusieron sin preguntarle) se sale de una reserva que hizo
     * otra persona, sin consecuencias para nadie: el resto del grupo la mantiene, con una
     * persona menos, y él queda libre para reservar otra sala. Si quedaría una sola
     * persona, la reserva se cancela, porque las salas se reservan para 2 a 5.
     */
    public function salirDeReservaSala(Request $request, Reserva $reserva)
    {
        $usuario = $request->user();

        if ($reserva->usuario_id === $usuario->id) {
            return response()->json(['message' => 'Tú hiciste esta reserva: para dejarla, cancélala.'], 409);
        }

        if (! $reserva->participantes()->whereKey($usuario->id)->exists()) {
            return response()->json(['message' => 'Solo puedes salirte de reservas en las que participas'], 403);
        }

        // La reserva queda bloqueada: si dos integrantes se salen a la vez, el segundo ve
        // que el primero ya se fue.
        [$motivo, $cancelada] = DB::transaction(function () use ($reserva, $usuario) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->first();

            if ($motivo = $this->motivoParaNoCambiarReserva($reserva, 'no puedes salirte de ella')) {
                return [$motivo, false];
            }

            $quedan = $reserva->participantes()->whereKeyNot($usuario->id)->count();
            if ($quedan < 2) {
                $reserva->update(['estado' => 'cancelada']);

                return [null, true];
            }

            $reserva->participantes()->detach($usuario->id);
            $reserva->update(['cantidad_personas' => $quedan]);

            return [null, false];
        });

        if ($motivo) {
            return response()->json(['message' => $motivo], 409);
        }

        $reserva->refresh();
        Bitacora::registrar(
            $cancelada ? 'salas.cancelar' : 'salas.integrantes',
            "Se salió desde el portal de {$reserva->descripcion()}"
                .($cancelada ? ': como quedaba una sola persona, la reserva se canceló' : ''),
            entidad: $reserva,
            datos: ['quitados' => $usuario->descripcion()],
            afectado: $usuario,
        );

        return response()->json(['cancelada' => $cancelada]);
    }

    /**
     * Solo cambia lo que todavía no ocurre: una reserva finalizada, cancelada o en que no
     * se presentaron queda como registro (igual que en el panel, que solo edita las
     * activas), y la que ya tiene la llave se cierra en el mesón. Una vencida sin
     * confirmar pasa aquí mismo a inasistencia, para que nadie la deje justo antes.
     */
    private function motivoParaNoCambiarReserva(Reserva $reserva, string $noSePuede): ?string
    {
        $this->reservaSalaService->liberarSiVencida($reserva);

        if ($reserva->estado !== 'activa') {
            return match ($reserva->estado) {
                'no_show' => "El grupo no llegó a tiempo a esta reserva: quedó registrada como inasistencia y ya {$noSePuede}.",
                'finalizada' => "Esta reserva ya terminó: queda en tu historial y {$noSePuede}.",
                'cancelada' => 'Esta reserva ya estaba cancelada.',
                default => "Esta reserva ya no está activa: {$noSePuede}.",
            };
        }

        if ($reserva->hora_prestamo_real) {
            return 'El grupo ya está usando la sala: la reserva se cierra en el mesón al devolver la llave.';
        }

        return null;
    }
}
