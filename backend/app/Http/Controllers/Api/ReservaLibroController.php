<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservaLibro;
use App\Models\Usuario;
use App\Rules\DiaConAtencion;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use App\Support\Bitacora;
use Illuminate\Http\Request;

class ReservaLibroController extends Controller
{
    public function __construct(
        private ReservaLibroService $reservaLibroService,
        private MultaService $multaService,
    ) {
    }

    public function index(Request $request)
    {
        $query = ReservaLibro::with(['libro', 'ejemplar']);

        if ($usuarioId = $request->query('usuario_id')) {
            $query->where('usuario_id', $usuarioId);
        }

        $reservas = $query->latest('fecha_reserva')->get();
        $this->reservaLibroService->enriquecerColaLibro($reservas);

        return response()->json($reservas);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'codigo_barras' => ['required', 'string'],
            // Opcionales: si el libro no está disponible, la reserva se une a la cola de
            // espera en vez de fallar, y fecha_retiro no aplica todavía (ver
            // ReservaLibroService::reservarOEncolar()).
            'fecha_reserva' => ['nullable', 'date'],
            'fecha_retiro' => ['nullable', 'date', 'after_or_equal:fecha_reserva', new DiaConAtencion('para el retiro')],
        ]);

        if ($inactivo = Usuario::findOrFail($data['usuario_id'])->mensajeInactivo()) {
            return response()->json(['message' => $inactivo], 409);
        }

        if ($bloqueo = $this->multaService->mensajeBloqueo($data['usuario_id'])) {
            return response()->json(['message' => $bloqueo], 409);
        }

        [$reserva, $error, $status] = $this->reservaLibroService->reservarOEncolar(
            $data['codigo_barras'],
            $data['usuario_id'],
            $data['fecha_reserva'] ?? null,
            $data['fecha_retiro'] ?? null,
            $request->user()->id,
        );

        if ($error) {
            return response()->json(['message' => $error], $status);
        }

        $reserva->loadMissing(['libro:id,titulo', 'usuario']);
        Bitacora::registrar(
            'reservas_libro.reservar',
            ($reserva->estado === 'en_cola' ? 'Agregó a la lista de espera de «' : 'Reservó «').$reserva->libro?->titulo.'»',
            entidad: $reserva,
            datos: ['codigo_barras' => $data['codigo_barras']],
            afectado: $reserva->usuario,
        );

        return response()->json($reserva, $status);
    }

    public function cancelar(ReservaLibro $reservaLibro)
    {
        $estadoAnterior = $reservaLibro->estado;

        if (! $this->reservaLibroService->cancelar($reservaLibro)) {
            return response()->json(['message' => $reservaLibro->estado === 'retirado'
                ? 'Este libro ya se retiró: la reserva queda en el historial y no se puede cancelar.'
                : 'Esta reserva ya estaba cancelada.'], 409);
        }

        Bitacora::registrar(
            'reservas_libro.cancelar',
            'Canceló la reserva de «'.$reservaLibro->libro?->titulo.'»',
            entidad: $reservaLibro,
            datos: ['estado' => [$estadoAnterior, 'cancelado']],
            afectado: $reservaLibro->usuario,
        );

        return response()->json($reservaLibro->fresh());
    }
}
