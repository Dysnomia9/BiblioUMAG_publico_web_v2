<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reserva;
use App\Models\Sala;
use App\Models\Usuario;
use App\Services\ReservaSalaService;
use Illuminate\Http\Request;

class SalaController extends Controller
{
    public function __construct(private ReservaSalaService $reservaSalaService)
    {
    }

    public function index(Request $request)
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
            // Los RUT deben pertenecer a usuarios ya registrados: no se admiten
            // visitantes externos en la reserva de logias (mismo criterio que el
            // portal de autoservicio en PortalController::reservarSala).
            'ruts.*' => ['required', 'string', 'distinct', 'exists:usuarios,rut'],
        ], [
            'ruts.*.distinct' => 'No puedes ingresar el mismo RUT más de una vez en la misma reserva.',
            'ruts.*.exists' => 'Uno de los RUT ingresados no corresponde a un usuario registrado en el sistema.',
        ]);

        if (count($data['ruts']) !== $data['cantidad_personas']) {
            return response()->json(['message' => 'Debe ingresar un RUT por cada persona indicada'], 422);
        }

        $inmediata = $data['inmediata'] ?? false;
        // Solo el admin puede agendar en una hora que ya pasó hoy (ej. registrar algo
        // que se atendió sin pasar por el sistema en su momento) — el resto del staff
        // sigue restringido a "desde ahora en adelante", igual que el portal.
        $permitirHoraPasada = $request->user()->rol === 'admin';
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

        return response()->json($reserva, 201);
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
        $reserva->update(['estado' => 'cancelada']);

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

        return response()->json($reserva);
    }

    /**
     * Confirmación manual de llegada (sin escaneo de código de barras) — sirve para
     * cualquier sala, incluidas las que no tienen codigo_barras (Seminarios, Postgrado,
     * AGACI). Es el "menú de confirmación": el staff ve el listado de reservas del día
     * y marca aquí quién sí se presentó dentro del plazo de 15 minutos.
     */
    public function confirmarLlegada(Request $request, Reserva $reserva)
    {
        try {
            $reserva = $this->reservaSalaService->registrarLlegada($reserva, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($reserva);
    }

    /** Libera de inmediato una reserva vencida sin confirmar, sin esperar a un nuevo intento de reserva sobre el mismo bloque. */
    public function liberarReserva(Reserva $reserva)
    {
        try {
            $reserva = $this->reservaSalaService->liberarPorNoPresentacion($reserva);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($reserva);
    }

    public function scanLogia(Request $request)
    {
        $data = $request->validate([
            'codigo_barras' => ['required', 'string'],
        ]);

        try {
            $reserva = $this->reservaSalaService->escanearLogia($data['codigo_barras'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($reserva->load('sala'));
    }
}
