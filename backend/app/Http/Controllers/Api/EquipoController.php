<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipo;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EquipoController extends Controller
{
    public function index(Request $request)
    {
        $query = Equipo::query();

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        if ($request->has('disponible')) {
            $query->where('disponible', filter_var($request->query('disponible'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(
            $query->orderBy('codigo_inventario')->get()
        );
    }

    /** Busca un equipo por su código de barras (escaneo en préstamo). */
    public function buscarPorCodigo(string $codigo)
    {
        $equipo = Equipo::where('codigo_barras', $codigo)->first();

        if (! $equipo) {
            return response()->json(['message' => 'Código de barras no encontrado en el sistema'], 404);
        }

        // Con su categoría, para decir qué es (notebook, bicicleta…) cuando lo escanean en otra pantalla.
        return response()->json($equipo->load('categoria:id,clave,nombre,nombre_plural'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::reglas(), [
            'tipo.exists' => 'Esa categoría de recurso no existe o está desactivada.',
        ]);

        return response()->json($this->registrar($data), 201);
    }

    /**
     * Registra varios recursos de una vez (la grilla de Recursos de apoyo). Entran todos o
     * ninguno: si una fila trae un código repetido, no queda la mitad cargada.
     */
    public function storeLote(Request $request)
    {
        $reglas = self::reglas();
        $data = $request->validate([
            'recursos' => ['required', 'array', 'min:1', 'max:50'],
            'recursos.*.codigo_inventario' => [...$reglas['codigo_inventario'], 'distinct'],
            'recursos.*.codigo_barras' => [...$reglas['codigo_barras'], 'distinct'],
            'recursos.*.tipo' => $reglas['tipo'],
        ], [
            'recursos.required' => 'Agrega al menos un recurso.',
            'recursos.max' => 'Se pueden registrar hasta 50 recursos de una vez.',
            'recursos.*.codigo_inventario.required' => 'Fila :position: falta el nombre.',
            'recursos.*.codigo_inventario.unique' => 'Fila :position: ya hay un recurso con ese nombre.',
            'recursos.*.codigo_inventario.distinct' => 'Fila :position: ese nombre está repetido en la lista.',
            'recursos.*.codigo_barras.required' => 'Fila :position: falta el código de barras.',
            'recursos.*.codigo_barras.unique' => 'Fila :position: ese código de barras ya es de otro recurso.',
            'recursos.*.codigo_barras.distinct' => 'Fila :position: ese código de barras está repetido en la lista.',
            'recursos.*.tipo.required' => 'Fila :position: elige la categoría.',
            'recursos.*.tipo.exists' => 'Fila :position: esa categoría no existe o está desactivada.',
        ]);

        $equipos = DB::transaction(fn () => array_map(fn (array $recurso) => $this->registrar($recurso), $data['recursos']));

        return response()->json($equipos, 201);
    }

    private static function reglas(): array
    {
        return [
            'codigo_inventario' => ['required', 'string', 'max:255', 'unique:equipos,codigo_inventario'],
            'codigo_barras' => ['required', 'string', 'max:255', 'unique:equipos,codigo_barras'],
            // La categoría de recurso de apoyo (Administración → Catálogos), solo si está activa.
            'tipo' => ['required', Rule::exists('categorias_recurso', 'clave')->where('activo', true)],
        ];
    }

    private function registrar(array $data): Equipo
    {
        $equipo = Equipo::create($data + ['disponible' => true, 'activo' => true]);

        $categoria = $equipo->categoria?->nombre ?? $equipo->tipo;
        Bitacora::registrar('equipos.agregar', "Agregó el recurso {$equipo->codigo_inventario} ({$categoria}, código {$equipo->codigo_barras})", entidad: $equipo);

        return $equipo;
    }

    public function cambiarActivo(Request $request, Equipo $equipo)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            // Dar de baja pide el motivo: se ve en el inventario y queda en la bitácora.
            'motivo' => [Rule::requiredIf(! $request->boolean('activo')), 'nullable', 'string', 'max:500'],
        ], [
            'motivo.required' => 'Indica el motivo de la baja: queda registrado en el inventario y en la bitácora.',
        ]);

        // No se puede dar de baja un recurso actualmente prestado (mismo criterio que
        // LibroController::cambiarEstado con 'de_baja') — primero debe devolverse.
        $activo = (bool) $data['activo'];
        if (! $activo && ! $equipo->disponible) {
            return response()->json(['message' => 'No se puede dar de baja un recurso que está prestado: primero tiene que devolverse.'], 409);
        }

        if ($equipo->activo !== $activo) {
            // Al reactivarlo se limpia el motivo: el de cada baja sigue en la bitácora.
            $equipo->update($activo
                ? ['activo' => true, 'motivo_baja' => null, 'dado_de_baja_el' => null]
                : ['activo' => false, 'motivo_baja' => $data['motivo'], 'dado_de_baja_el' => now()]);

            Bitacora::registrar(
                $activo ? 'equipos.reactivar' : 'equipos.dar_de_baja',
                $activo
                    ? "Reactivó el recurso {$equipo->codigo_inventario}"
                    : "Dio de baja el recurso {$equipo->codigo_inventario}. Motivo: {$data['motivo']}",
                entidad: $equipo,
                datos: $activo ? [] : ['motivo' => $data['motivo']],
            );
        }

        return response()->json($equipo);
    }
}
