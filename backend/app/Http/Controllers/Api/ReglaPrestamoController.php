<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReglaPrestamo;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración de las reglas de préstamo (plazo, máximo a la vez y multa por tipo
 * de usuario, tipo de material y ubicación). Cuál se aplica a cada préstamo lo
 * decide App\Services\ReglaPrestamoService.
 */
class ReglaPrestamoController extends Controller
{
    private const RELACIONES = ['tipoMaterial:id,nombre', 'ubicacion:id,nombre'];

    private const REPETIDA = 'Ya hay una regla para ese tipo de usuario, tipo de material y ubicación: edítala en vez de crear otra.';

    public function index()
    {
        return response()->json([
            'reglas' => ReglaPrestamo::with(self::RELACIONES)
                ->orderByRaw('tipo_usuario NULLS FIRST, tipo_material_id NULLS FIRST, ubicacion_id NULLS FIRST')
                ->get(),
            // Lo que se aplica cuando ninguna regla calza.
            'sin_regla' => [
                'max_ejemplares' => 1,
                'multa_por_dia' => (int) config('multas.monto_dia'),
                'tope_multa' => config('multas.tope_maximo'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglas());
        if ($this->repetida($data)) {
            return response()->json(['message' => self::REPETIDA], 422);
        }

        $regla = ReglaPrestamo::create($data);

        Bitacora::registrar('configuracion.crear_regla_prestamo', "Creó la regla de préstamo «{$regla->descripcion()}»", entidad: $regla, datos: $data);

        return response()->json($regla->load(self::RELACIONES), 201);
    }

    public function update(Request $request, ReglaPrestamo $reglaPrestamo)
    {
        $data = $request->validate($this->reglas());
        if ($this->repetida([...$reglaPrestamo->only(['tipo_usuario', 'tipo_material_id', 'ubicacion_id']), ...$data], $reglaPrestamo)) {
            return response()->json(['message' => self::REPETIDA], 422);
        }

        $antes = $reglaPrestamo->only(array_keys($data));
        $reglaPrestamo->update($data);

        Bitacora::registrar(
            'configuracion.editar_regla_prestamo',
            "Editó la regla de préstamo «{$reglaPrestamo->descripcion()}»",
            entidad: $reglaPrestamo,
            datos: Bitacora::cambios($antes, $reglaPrestamo->only(array_keys($data))),
        );

        return response()->json($reglaPrestamo->load(self::RELACIONES));
    }

    public function destroy(ReglaPrestamo $reglaPrestamo)
    {
        $descripcion = $reglaPrestamo->descripcion();
        $datos = $reglaPrestamo->only(['tipo_usuario', 'tipo_material_id', 'ubicacion_id', 'dias_prestamo', 'max_ejemplares', 'multa_por_dia', 'tope_multa', 'prestable']);
        $reglaPrestamo->delete();

        Bitacora::registrar('configuracion.eliminar_regla_prestamo', "Eliminó la regla de préstamo «{$descripcion}»", datos: $datos);

        return response()->json(null, 204);
    }

    /** Una sola regla por combinación; null cuenta como un valor más, igual que en el índice único. */
    private function repetida(array $data, ?ReglaPrestamo $ignorar = null): bool
    {
        $query = ReglaPrestamo::query()->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id));
        foreach (['tipo_usuario', 'tipo_material_id', 'ubicacion_id'] as $columna) {
            $valor = $data[$columna] ?? null;
            $valor === null ? $query->whereNull($columna) : $query->where($columna, $valor);
        }

        return $query->exists();
    }

    private function reglas(): array
    {
        return [
            'tipo_usuario' => ['nullable', Rule::in(ReglaPrestamo::TIPOS_USUARIO)],
            'tipo_material_id' => ['nullable', 'exists:tipos_material,id'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'dias_prestamo' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_ejemplares' => ['nullable', 'integer', 'min:0', 'max:100'],
            'multa_por_dia' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'tope_multa' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'prestable' => ['boolean'],
        ];
    }
}
