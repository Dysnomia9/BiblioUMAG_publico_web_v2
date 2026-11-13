<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\Prestamo;
use App\Models\UsoSinRut;
use App\Support\Bitacora;
use Illuminate\Http\Request;

/**
 * «Devolución sin RUT»: el libro que alguien usó y dejó en la mesa sin pedirlo se
 * pistolea en el mesón y queda registrado que se usó (Reportes lo cuenta como «Sin RUT»).
 */
class UsoSinRutController extends Controller
{
    /**
     * Los de hoy, del más reciente al más antiguo, para la lista del mesón: de a pocos
     * (5 por defecto) y más si se pide con «Ver más».
     */
    public function hoy(Request $request)
    {
        $limite = (int) ($request->validate(['limite' => ['nullable', 'integer', 'min:1', 'max:500']])['limite'] ?? 5);
        $hoy = UsoSinRut::whereDate('fecha_hora', today());

        return response()->json([
            'total' => (clone $hoy)->count(),
            'recientes' => (clone $hoy)->latest('fecha_hora')->latest('id')->limit($limite)
                ->get(['id', 'libro_titulo', 'codigo_barras', 'fecha_hora', 'registrado_por', 'registrado_por_staff_id']),
        ]);
    }

    public function store(Request $request)
    {
        $codigo = trim((string) $request->validate(['codigo' => ['required', 'string', 'max:64']])['codigo']);
        $ejemplar = Ejemplar::porCodigo($codigo)->with('libro')->first();

        if (! $ejemplar) {
            return response()->json(['message' => 'Ese código no es de ningún libro del catálogo'], 404);
        }

        // Si está prestado, lo que corresponde es cerrar el préstamo: registrarlo sin RUT lo dejaría abierto.
        $prestamo = Prestamo::with('usuario:id,nombre,apellido,rut')->where('ejemplar_id', $ejemplar->id)->where('estado', '!=', 'devuelto')->first();
        if ($prestamo) {
            $quien = $prestamo->usuario ? "{$prestamo->usuario->nombre} {$prestamo->usuario->apellido} ({$prestamo->usuario->rut})" : 'otra persona';

            return response()->json(['message' => "Este libro está prestado a {$quien}: devuélvalo con «Devolución normal»."], 409);
        }

        $staff = $request->user();
        $uso = UsoSinRut::create([
            'ejemplar_id' => $ejemplar->id,
            'libro_titulo' => $ejemplar->tituloConCopia(),
            'codigo_barras' => $ejemplar->codigo_barras ?? $codigo,
            'fecha_hora' => now(),
            'registrado_por' => $staff->nombre,
            'registrado_por_staff_id' => $staff->id,
        ]);

        Bitacora::registrar('prestamos.sin_rut', "Registró el uso sin RUT de «{$uso->libro_titulo}»", entidad: $uso, datos: ['codigo_barras' => $uso->codigo_barras]);

        return response()->json($uso, 201);
    }

    /** Deshacer una lectura equivocada: solo la de hoy y solo quien la registró. */
    public function destroy(Request $request, UsoSinRut $uso)
    {
        if (! $uso->fecha_hora->isToday() || $uso->registrado_por_staff_id !== $request->user()->id) {
            return response()->json(['message' => 'Solo se puede deshacer una lectura propia del día.'], 403);
        }

        $uso->delete();
        Bitacora::registrar('prestamos.sin_rut_deshacer', "Deshizo el uso sin RUT de «{$uso->libro_titulo}»", datos: ['codigo_barras' => $uso->codigo_barras]);

        return response()->noContent();
    }
}
