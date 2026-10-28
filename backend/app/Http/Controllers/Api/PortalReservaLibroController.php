<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Autoservicio de reservas de libro desde el portal virtual (/mi/*): el usuario
 * reserva un libro o se une a su cola de espera sin pasar por mesón.
 */
class PortalReservaLibroController extends Controller
{
    public function __construct(
        private ReservaLibroService $reservaLibroService,
        private MultaService $multaService,
    ) {
    }

    public function index(Request $request)
    {
        $reservas = ReservaLibro::with('libro:id,titulo')
            ->where('usuario_id', $request->user()->id)
            ->whereIn('estado', ['pendiente', 'en_cola'])
            ->latest('created_at')
            ->get();

        $this->reservaLibroService->enriquecerColaLibro($reservas);

        return response()->json($reservas);
    }

    /**
     * Lo que el portal muestra antes de confirmar una reserva: hasta qué día tendría
     * para retirar el libro si lo reserva ahora, y cuántas reservas activas (por
     * retirar o en espera) puede tener a la vez — así el aviso coincide con lo que
     * store() va a aplicar. "bloqueo"
     * trae el motivo si tiene multas pendientes o libros vencidos, para apagar el botón de reservar.
     */
    public function condiciones(Request $request)
    {
        return response()->json([
            'fecha_retiro' => $this->reservaLibroService->fechaLimiteDeRetiro(),
            'dias_para_retirar' => config('reservas_libro.dias_para_retirar'),
            'maximo_por_persona' => config('reservas_libro.maximo_por_persona'),
            'bloqueo' => $this->multaService->mensajeBloqueo($request->user(), paraElUsuario: true),
            // Tras cancelar hace poco: el aviso y hasta cuándo dura, para apagar el botón.
            'espera' => ($hasta = $this->esperaHasta($request->user())) ? $this->mensajeEspera($hasta) : null,
            'espera_hasta' => $hasta?->toIso8601String(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'libro_id' => ['required', 'integer', 'exists:libros,id'],
        ]);

        if ($bloqueo = $this->multaService->mensajeBloqueo($request->user(), paraElUsuario: true)) {
            return response()->json(['message' => $bloqueo], 409);
        }

        if ($hasta = $this->esperaHasta($request->user())) {
            return response()->json(['message' => $this->mensajeEspera($hasta)], 429);
        }

        // A diferencia del staff (que escanea el código de barras de una copia
        // concreta), el usuario del portal navega el catálogo por título — no le
        // importa cuál copia física le toque (ver ReservaLibroService::reservarOEncolarPorLibro()).
        // Tampoco elige fechas, se calculan automáticamente.
        [$reserva, $error, $status] = $this->reservaLibroService->reservarOEncolarPorLibro(
            $data['libro_id'],
            $request->user()->id,
        );

        if ($error) {
            return response()->json(['message' => $error], $status);
        }

        $reserva->loadMissing('libro:id,titulo');
        Bitacora::registrar(
            'reservas_libro.reservar',
            ($reserva->estado === 'en_cola' ? 'Se unió desde el portal a la lista de espera de «' : 'Reservó desde el portal «').$reserva->libro?->titulo.'»',
            entidad: $reserva,
            afectado: $request->user(),
        );

        // Sin los datos internos del libro ni de la copia (precio, ubicación, notas).
        return response()->json($reserva->unsetRelation('ejemplar')->load('libro:id,titulo'), $status);
    }

    public function cancelar(Request $request, ReservaLibro $reservaLibro)
    {
        if ($reservaLibro->usuario_id !== $request->user()->id) {
            return response()->json(['message' => 'Solo puedes cancelar tus propias reservas'], 403);
        }

        // Solo lo que sigue activo: una reserva retirada o cancelada queda en el historial.
        if (! $this->reservaLibroService->cancelar($reservaLibro)) {
            return response()->json(['message' => $reservaLibro->estado === 'retirado'
                ? 'Este libro ya se retiró: la reserva queda en tu historial y no se puede cancelar.'
                : 'Esta reserva ya estaba cancelada.'], 409);
        }

        // Desde ahora espera unos minutos antes de pedir otro libro (config/reservas_libro.php).
        $minutos = (int) config('reservas_libro.espera_tras_cancelar');
        if ($minutos > 0) {
            $hasta = now()->addMinutes($minutos);
            Cache::put($this->claveEspera($request->user()), $hasta->getTimestamp(), $hasta);
        }

        Bitacora::registrar('reservas_libro.cancelar', 'Canceló desde el portal su reserva de «'.$reservaLibro->libro?->titulo.'»', entidad: $reservaLibro, afectado: $request->user());

        return response()->json($reservaLibro->fresh());
    }

    /** Hasta cuándo debe esperar para pedir otro libro quien canceló hace poco, o null. */
    private function esperaHasta(Usuario $usuario): ?Carbon
    {
        $hasta = Cache::get($this->claveEspera($usuario));

        return is_int($hasta) && $hasta > now()->getTimestamp() ? now()->setTimestamp($hasta) : null;
    }

    private function mensajeEspera(Carbon $hasta): string
    {
        $minutos = (int) config('reservas_libro.espera_tras_cancelar');

        return "Cancelaste una reserva hace poco. Para evitar reservas y cancelaciones seguidas, hay que esperar {$minutos} minutos: podrás pedir un libro de nuevo a las {$hasta->format('H:i')}.";
    }

    private function claveEspera(Usuario $usuario): string
    {
        return "reservas_libro.espera:{$usuario->id}";
    }
}
