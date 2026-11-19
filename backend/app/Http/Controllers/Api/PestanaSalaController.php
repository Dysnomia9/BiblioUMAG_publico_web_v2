<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PestanaSala;
use App\Models\Sala;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Las pestañas del mesón de salas: crearlas, renombrarlas, ordenarlas, elegir qué salas van
 * en cada una y eliminarlas. Eliminar una pestaña no toca sus salas: quedan en «Otras».
 */
class PestanaSalaController extends Controller
{
    public function index()
    {
        return response()->json($this->listado());
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $pestana = PestanaSala::create($data + [
            'orden' => (int) PestanaSala::where('apartada', $data['apartada'] ?? false)->max('orden') + 1,
        ]);

        Bitacora::registrar('configuracion.pestana_sala', "Creó la pestaña de salas «{$pestana->nombre}»", entidad: $pestana);

        return response()->json($this->listado(), 201);
    }

    public function update(Request $request, PestanaSala $pestana)
    {
        $data = $this->validar($request, $pestana);
        $antes = $pestana->only(array_keys($data));
        $pestana->update($data);

        Bitacora::registrar(
            'configuracion.pestana_sala',
            "Editó la pestaña de salas «{$pestana->nombre}»",
            entidad: $pestana,
            datos: Bitacora::cambios($antes, $pestana->only(array_keys($data))),
        );

        return response()->json($this->listado());
    }

    /** El orden de las pestañas, de izquierda a derecha (las apartadas siguen al final). */
    public function ordenar(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct', Rule::exists('pestanas_salas', 'id')],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $orden => $id) {
                PestanaSala::whereKey($id)->update(['orden' => $orden]);
            }
        });

        return response()->json($this->listado());
    }

    /**
     * Las salas que van en esta pestaña: las elegidas pasan a ella (aunque estuvieran en otra)
     * y las que tenía y ya no se eligieron quedan sin pestaña («Otras»).
     */
    public function salas(Request $request, PestanaSala $pestana)
    {
        $data = $request->validate([
            'sala_ids' => ['present', 'array'],
            'sala_ids.*' => ['integer', 'distinct', Rule::exists('salas', 'id')],
        ]);

        DB::transaction(function () use ($pestana, $data) {
            $pestana->salas()->whereNotIn('id', $data['sala_ids'])->update(['pestana_id' => null]);
            Sala::whereIn('id', $data['sala_ids'])->update(['pestana_id' => $pestana->id]);
        });

        $codigos = Sala::whereIn('id', $data['sala_ids'])->enOrden()->pluck('codigo')->implode(', ');
        Bitacora::registrar(
            'configuracion.pestana_sala',
            "Dejó en la pestaña «{$pestana->nombre}» las salas: ".($codigos ?: 'ninguna'),
            entidad: $pestana,
        );

        return response()->json($this->listado());
    }

    public function destroy(PestanaSala $pestana)
    {
        $nombre = $pestana->nombre;
        $salas = $pestana->salas()->count();
        // pestana_id queda en null (nullOnDelete): las salas pasan a «Otras».
        $pestana->delete();

        Bitacora::registrar(
            'configuracion.pestana_sala',
            "Eliminó la pestaña de salas «{$nombre}»".($salas ? " ({$salas} salas pasaron a «Otras»)" : ''),
        );

        return response()->json($this->listado());
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?PestanaSala $pestana = null): array
    {
        return $request->validate([
            'nombre' => [$pestana ? 'sometimes' : 'required', 'string', 'max:40', Rule::notIn(['Otras', 'otras', 'OTRAS', 'Búsqueda']), Rule::unique('pestanas_salas', 'nombre')->ignore($pestana?->id)],
            'apartada' => ['sometimes', 'boolean'],
        ], [
            'nombre.unique' => 'Ya hay una pestaña con ese nombre.',
            'nombre.not_in' => '«Otras» es la pestaña de las salas que no están en ninguna: elija otro nombre.',
            'nombre.max' => 'El nombre de la pestaña va hasta 40 caracteres.',
        ]);
    }

    /** @return list<array{id: int, nombre: string, orden: int, apartada: bool}> */
    private function listado(): array
    {
        return PestanaSala::enOrden()->get(['id', 'nombre', 'orden', 'apartada'])->toArray();
    }
}
