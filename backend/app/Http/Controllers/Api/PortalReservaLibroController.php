<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservaLibro;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use Illuminate\Http\Request;

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
     * trae el motivo si tiene multas pendientes, para apagar el botón de reservar.
     */
    public function condiciones(Request $request)
    {
        return response()->json([
            'fecha_retiro' => $this->reservaLibroService->fechaLimiteDeRetiro(),
            'dias_para_retirar' => config('reservas_libro.dias_para_retirar'),
            'maximo_por_persona' => config('reservas_libro.maximo_por_persona'),
            'bloqueo' => $this->multaService->mensajeBloqueo($request->user(), paraElUsuario: true),
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

        $this->reservaLibroService->cancelar($reservaLibro);

        Bitacora::registrar('reservas_libro.cancelar', 'Canceló desde el portal su reserva de «'.$reservaLibro->libro?->titulo.'»', entidad: $reservaLibro, afectado: $request->user());

        return response()->json($reservaLibro->fresh());
    }
}
