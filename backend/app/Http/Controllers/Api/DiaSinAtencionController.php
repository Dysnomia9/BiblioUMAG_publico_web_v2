<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaSinAtencion;
use App\Support\Bitacora;
use Illuminate\Http\Request;

/**
 * Feriados, vacaciones y cierres (tabla dias_sin_atencion). Los consulta todo el
 * personal (el mesón avisa si la devolución cae en uno); los administra quien tiene
 * los catálogos del sistema.
 */
class DiaSinAtencionController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        return response()->json(
            DiaSinAtencion::with('ubicacion:id,nombre')
                ->when($request->query('desde'), fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
                ->when($request->query('hasta'), fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
                ->orderBy('fecha')
                ->orderByRaw('ubicacion_id NULLS FIRST')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $repetido = DiaSinAtencion::whereDate('fecha', $data['fecha'])
            ->where(fn ($q) => empty($data['ubicacion_id']) ? $q->whereNull('ubicacion_id') : $q->where('ubicacion_id', $data['ubicacion_id']))
            ->exists();
        if ($repetido) {
            return response()->json(['message' => 'Ese día ya está registrado como sin atención para esa ubicación.'], 422);
        }

        $dia = DiaSinAtencion::create($data)->load('ubicacion:id,nombre');

        Bitacora::registrar(
            'configuracion.crear_dia_sin_atencion',
            'Registró el '.$dia->fecha->format('d/m/Y').' como día sin atención'.($dia->ubicacion ? " en {$dia->ubicacion->nombre}" : '').($dia->motivo ? " ({$dia->motivo})" : ''),
            entidad: $dia,
        );

        return response()->json($dia, 201);
    }

    public function update(Request $request, DiaSinAtencion $diaSinAtencion)
    {
        $data = $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $antes = $diaSinAtencion->only(['motivo']);
        $diaSinAtencion->update($data);

        Bitacora::registrar(
            'configuracion.editar_dia_sin_atencion',
            'Editó el día sin atención del '.$diaSinAtencion->fecha->format('d/m/Y'),
            entidad: $diaSinAtencion,
            datos: Bitacora::cambios($antes, $diaSinAtencion->only(['motivo'])),
        );

        return response()->json($diaSinAtencion->load('ubicacion:id,nombre'));
    }

    public function destroy(DiaSinAtencion $diaSinAtencion)
    {
        $diaSinAtencion->loadMissing('ubicacion:id,nombre');
        $descripcion = 'Quitó el '.$diaSinAtencion->fecha->format('d/m/Y').' de los días sin atención'.($diaSinAtencion->ubicacion ? " de {$diaSinAtencion->ubicacion->nombre}" : '');
        $diaSinAtencion->delete();

        Bitacora::registrar('configuracion.eliminar_dia_sin_atencion', $descripcion);

        return response()->json(null, 204);
    }
}
