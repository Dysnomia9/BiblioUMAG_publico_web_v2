<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservaLibro;
use App\Services\ReservaLibroService;
use Illuminate\Http\Request;

/**
 * Autoservicio de reservas de libro desde el portal virtual (/mi/*) — antes
 * un usuario final solo podía ver el catálogo y reservar salas por su
 * cuenta; para pedir un libro tenía que ir a mesón. PortalController ya
 * concentraba varias responsabilidades (ver Deuda técnica en CLAUDE.md), así
 * que esto se separó en su propio controller en vez de seguir agregándole
 * métodos.
 */
class PortalReservaLibroController extends Controller
{
    public function __construct(private ReservaLibroService $reservaLibroService)
    {
    }

    public function index(Request $request)
    {
        $reservas = ReservaLibro::with('libro')
            ->where('usuario_id', $request->user()->id)
            ->whereIn('estado', ['pendiente', 'en_cola'])
            ->latest('created_at')
            ->get();

        $this->reservaLibroService->enriquecerColaLibro($reservas);

        return response()->json($reservas);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'libro_id' => ['required', 'integer', 'exists:libros,id'],
        ]);

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

        return response()->json($reserva, $status);
    }

    public function cancelar(Request $request, ReservaLibro $reservaLibro)
    {
        if ($reservaLibro->usuario_id !== $request->user()->id) {
            return response()->json(['message' => 'Solo puedes cancelar tus propias reservas'], 403);
        }

        $this->reservaLibroService->cancelar($reservaLibro);

        return response()->json($reservaLibro->fresh());
    }
}
