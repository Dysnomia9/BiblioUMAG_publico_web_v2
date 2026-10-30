<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaSinAtencion;
use App\Models\Ejemplar;
use App\Models\ReglaPrestamo;
use App\Models\Ubicacion;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Catálogo de ubicaciones físicas de los ejemplares — solo admin puede crearlas, renombrarlas y eliminarlas (Administración), a diferencia de autores/categorías/carreras que cualquier staff puede crear al vuelo desde catalogación. */
class UbicacionController extends Controller
{
    public function index(Request $request)
    {
        // Administración muestra cuántas copias tiene cada una, para decidir antes de eliminarla.
        return response()->json(Ubicacion::orderBy('nombre')
            ->when($request->boolean('con_copias'), fn ($q) => $q->withCount('ejemplares as copias'))
            ->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:ubicaciones,nombre'],
        ]);

        $ubicacion = Ubicacion::create($data);

        Bitacora::registrar('configuracion.crear_ubicacion', "Creó la ubicación «{$ubicacion->nombre}»", entidad: $ubicacion);

        return response()->json($ubicacion, 201);
    }

    /** Le cambia el nombre: sus copias, reglas y días sin atención la siguen teniendo. */
    public function update(Request $request, Ubicacion $ubicacion)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('ubicaciones', 'nombre')->ignore($ubicacion->id)],
        ], [
            'nombre.unique' => 'Ya existe una ubicación con ese nombre.',
        ]);

        $antes = $ubicacion->nombre;
        $ubicacion->update($data);

        Bitacora::registrar(
            'configuracion.editar_ubicacion',
            "Cambió el nombre de la ubicación «{$antes}» a «{$ubicacion->nombre}»",
            entidad: $ubicacion,
            datos: ['nombre' => [$antes, $ubicacion->nombre]],
        );

        return response()->json($ubicacion);
    }

    /**
     * Elimina una ubicación. Sus copias no quedan sin ubicación sin que nadie lo decida: si
     * tiene, se pide mover_a (otra ubicación) y pasan ahí antes de borrarla. Las reglas de
     * préstamo y los días sin atención de la ubicación se cambian antes, a mano.
     */
    public function destroy(Request $request, Ubicacion $ubicacion)
    {
        $data = $request->validate([
            'mover_a' => ['nullable', 'integer', 'exists:ubicaciones,id', Rule::notIn([$ubicacion->id])],
        ], [
            'mover_a.not_in' => 'Elige otra ubicación para sus copias, no la misma que se elimina.',
        ]);

        $reglas = ReglaPrestamo::where('ubicacion_id', $ubicacion->id)->count();
        $dias = DiaSinAtencion::where('ubicacion_id', $ubicacion->id)->count();
        if ($reglas || $dias) {
            $usos = array_filter([
                $reglas ? ($reglas === 1 ? '1 regla de préstamo' : "{$reglas} reglas de préstamo") : null,
                $dias ? ($dias === 1 ? '1 día sin atención' : "{$dias} días sin atención") : null,
            ]);

            return response()->json(['message' => "«{$ubicacion->nombre}» se usa en ".implode(' y ', $usos).': cámbialos o elimínalos primero (Administración → Reglas de préstamo y días sin atención).'], 409);
        }

        $copias = Ejemplar::where('ubicacion_id', $ubicacion->id)->count();
        if ($copias && empty($data['mover_a'])) {
            return response()->json(['message' => "«{$ubicacion->nombre}» tiene {$copias} ".($copias === 1 ? 'copia' : 'copias').': elige a qué ubicación pasan antes de eliminarla.'], 422);
        }

        $destino = $copias ? Ubicacion::find($data['mover_a']) : null;

        DB::transaction(function () use ($ubicacion, $destino) {
            if ($destino) {
                Ejemplar::where('ubicacion_id', $ubicacion->id)->update(['ubicacion_id' => $destino->id, 'updated_at' => now()]);
            }
            $ubicacion->delete();
        });

        Bitacora::registrar(
            'configuracion.eliminar_ubicacion',
            "Eliminó la ubicación «{$ubicacion->nombre}»".($destino ? ". Sus {$copias} ".($copias === 1 ? 'copia pasó' : 'copias pasaron')." a «{$destino->nombre}»" : ''),
            datos: ['ubicacion' => $ubicacion->nombre, 'copias' => $copias, 'movidas_a' => $destino?->nombre],
        );

        return response()->json(['eliminada' => $ubicacion->nombre, 'copias_movidas' => $copias, 'destino' => $destino?->nombre]);
    }
}
