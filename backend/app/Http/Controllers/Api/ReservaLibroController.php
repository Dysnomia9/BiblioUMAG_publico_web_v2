<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservaLibro;
use App\Services\ReservaLibroService;
use Illuminate\Http\Request;

class ReservaLibroController extends Controller
{
    public function __construct(private ReservaLibroService $reservaLibroService)
    {
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
            'fecha_retiro' => ['nullable', 'date', 'after_or_equal:fecha_reserva'],
        ]);

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

        return response()->json($reserva, $status);
    }

    public function cancelar(ReservaLibro $reservaLibro)
    {
        $this->reservaLibroService->cancelar($reservaLibro);

        return response()->json($reservaLibro->fresh());
    }
}
