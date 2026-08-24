<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodigoAcceso;
use App\Models\Entrada;
use App\Models\Libro;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use App\Services\ReservaSalaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PortalController extends Controller
{
    private const CAPACIDAD_SALA = 220;

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
            'capacidad' => self::CAPACIDAD_SALA,
        ]);
    }

    /**
     * Autoservicio de la Constancia de No Multa (mismo dato que
     * UsuarioController::porRut(), pero acotado al propio usuario autenticado del
     * portal — no recibe RUT por parámetro para no poder consultar la deuda de otra
     * persona).
     */
    public function misMultas(Request $request)
    {
        $usuario = $request->user();

        $multas = $usuario->prestamos()
            ->where('multa_estado', 'pendiente')
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(multa_monto), 0) as monto_total')
            ->first();

        $usuario->setAttribute('multas_pendientes', [
            'cantidad' => (int) $multas->cantidad,
            'monto_total' => (int) $multas->monto_total,
        ]);

        return response()->json($usuario);
    }

    public function registrarEntrada(Request $request)
    {
        $data = $request->validate([
            'rut' => ['sometimes', 'string'],
            'codigo' => ['required_if:via,qr', 'string'],
            'via' => ['required', 'in:manual,qr'],
        ]);

        $usuario = $request->user();

        if ($data['via'] === 'manual' && ($data['rut'] ?? null) !== $usuario->rut) {
            return response()->json(['message' => 'El RUT ingresado no coincide con tu cuenta'], 422);
        }

        if ($data['via'] === 'qr' && $data['codigo'] !== CodigoAcceso::vigente()->codigo) {
            return response()->json(['message' => 'El código QR no es válido. Pide al personal que lo actualice.'], 422);
        }

        // La salida se marca en el mismo instante que la entrada — ver la nota en
        // EntradaController::store() sobre por qué (Horizon no registra un evento de
        // salida por separado). Se usa el mismo timestamp para ambos campos.
        $ahora = now();
        $entrada = Entrada::create([
            'usuario_id' => $usuario->id,
            'via' => $data['via'],
            'fecha_hora_entrada' => $ahora,
            'fecha_hora_salida' => $ahora,
        ]);

        return response()->json([
            'entrada' => $entrada,
            'usuario' => $usuario,
        ], 201);
    }

    public function catalogo(Request $request)
    {
        // Solo obras con al menos un ejemplar en estante (aunque esté prestado ahora
        // mismo — el usuario puede reservarlo/unirse a la cola desde el portal).
        $query = Libro::whereHas('ejemplares', fn ($q) => $q->where('estado_proceso', 'en_estante'))
            ->with(['autores', 'categorias'])
            ->withCount([
                'ejemplares as ejemplares_total' => fn ($q) => $q->where('estado_proceso', 'en_estante'),
                'ejemplares as ejemplares_disponibles' => fn ($q) => $q->where('estado_proceso', 'en_estante')->where('disponible', true),
            ]);

        if ($busqueda = $request->query('q')) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('titulo', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('autores', fn ($a) => $a->where('nombre', 'ilike', "%{$busqueda}%"))
                    ->orWhereHas('categorias', fn ($c) => $c->where('nombre', 'ilike', "%{$busqueda}%"));
            });
        }

        return response()->json(
            $query->orderBy('titulo')->get()
        );
    }

    public function salas(Request $request)
    {
        $fecha = $request->query('fecha', now()->toDateString());
        $salas = Sala::orderBy('id')->get();

        return response()->json([
            'fecha' => $fecha,
            'apertura' => config('salas.apertura'),
            'cierre' => config('salas.cierre'),
            'granularidad' => config('salas.granularidad'),
            'duracion_minima' => config('salas.duracion_minima'),
            'duracion_maxima' => config('salas.duracion_maxima'),
            'cuota_diaria' => config('salas.cuota_diaria'),
            'salas' => $this->reservaSalaService->vistaDelDia($salas, $fecha),
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
            // A diferencia del registro de entrada externo, aquí los RUT deben
            // pertenecer a usuarios ya registrados: no se admiten visitantes externos.
            'ruts.*' => ['required', 'string', 'distinct', 'exists:usuarios,rut'],
        ], [
            'ruts.*.exists' => 'Uno de los RUT ingresados no corresponde a un usuario registrado en el sistema.',
            'ruts.*.distinct' => 'No puedes ingresar el mismo RUT más de una vez en la misma reserva.',
        ]);

        // Los alumnos solo pueden reservar para el día de hoy — a diferencia del staff
        // (SalaController::storeReserva), que sí puede reservar con anticipación.
        if ($data['fecha'] !== now()->toDateString()) {
            return response()->json(['message' => 'Solo puedes reservar una sala para el día de hoy'], 422);
        }

        if (count($data['ruts']) !== $data['cantidad_personas']) {
            return response()->json(['message' => 'Debe ingresar un RUT por cada persona indicada'], 422);
        }

        $usuario = $request->user();

        $inmediata = $data['inmediata'] ?? false;
        $error = $this->reservaSalaService->validarTramo($data, $inmediata);
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

        $reserva->participantes()->attach(Usuario::whereIn('rut', $data['ruts'])->pluck('id'));

        return response()->json($reserva, 201);
    }

    /** Soft delete vía estado — ver SalaController::destroyReserva() para el detalle. */
    public function cancelarReservaSala(Request $request, Reserva $reserva)
    {
        if ($reserva->usuario_id !== $request->user()->id) {
            return response()->json(['message' => 'Solo puedes cancelar tus propias reservas'], 403);
        }

        $reserva->update(['estado' => 'cancelada']);

        return response()->json(null, 204);
    }
}
