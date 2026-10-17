<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaRecurso;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Categorías de recursos de apoyo (lo que se presta y no es un libro): audífonos,
 * notebooks, cargadores, bicicletas… Todo el personal las lee (Préstamos, Recursos de
 * apoyo, Reportes); crearlas y editarlas exige 'catalogos.gestionar'. No se borran:
 * una categoría que ya no se presta se desactiva, y sus recursos y préstamos quedan.
 */
class CategoriaRecursoController extends Controller
{
    public function index(Request $request)
    {
        $query = CategoriaRecurso::query()
            ->withCount([
                'equipos as recursos_total',
                'equipos as recursos_activos' => fn ($q) => $q->where('activo', true),
                'equipos as recursos_disponibles' => fn ($q) => $q->where('activo', true)->where('disponible', true),
            ]);

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderByDesc('activo')->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglas(), $this->mensajes());

        $categoria = CategoriaRecurso::create($data + ['clave' => $this->claveUnica($data['nombre']), 'activo' => true]);

        Bitacora::registrar(
            'configuracion.crear_categoria_recurso',
            "Creó la categoría de recursos de apoyo «{$categoria->nombre}»",
            entidad: $categoria,
            datos: $categoria->only(['clave', 'nombre_plural', 'icono', 'color', 'limite_por_persona', 'indicaciones']),
        );

        return response()->json($categoria->fresh(), 201);
    }

    public function update(Request $request, CategoriaRecurso $categoriaRecurso)
    {
        // Edición parcial: activar/desactivar manda solo `activo`.
        $reglas = array_map(fn ($regla) => ['sometimes', ...$regla], $this->reglas($categoriaRecurso));
        $data = $request->validate([...$reglas, 'activo' => ['sometimes', 'boolean']], $this->mensajes());

        $antes = $categoriaRecurso->only(array_keys($data));
        $categoriaRecurso->update($data);

        $cambios = Bitacora::cambios($antes, $categoriaRecurso->only(array_keys($data)));
        Bitacora::registrar(
            match (true) {
                isset($cambios['activo']) && ! $categoriaRecurso->activo => 'configuracion.desactivar_categoria_recurso',
                isset($cambios['activo']) => 'configuracion.reactivar_categoria_recurso',
                default => 'configuracion.editar_categoria_recurso',
            },
            match (true) {
                isset($cambios['activo']) && ! $categoriaRecurso->activo => "Desactivó la categoría de recursos de apoyo «{$categoriaRecurso->nombre}»",
                isset($cambios['activo']) => "Reactivó la categoría de recursos de apoyo «{$categoriaRecurso->nombre}»",
                default => "Editó la categoría de recursos de apoyo «{$categoriaRecurso->nombre}»",
            },
            entidad: $categoriaRecurso,
            datos: $cambios,
        );

        return response()->json($categoriaRecurso);
    }

    private function reglas(?CategoriaRecurso $categoria = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('categorias_recurso', 'nombre')->ignore($categoria?->id)],
            'nombre_plural' => ['required', 'string', 'max:100'],
            'icono' => ['required', Rule::in(CategoriaRecurso::ICONOS)],
            'color' => ['required', Rule::in(CategoriaRecurso::COLORES)],
            'limite_por_persona' => ['nullable', 'integer', 'min:1', 'max:20'],
            'indicaciones' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.unique' => 'Ya existe una categoría con ese nombre.',
            'nombre.required' => 'Escribe el nombre de la categoría (en singular, ej. Bicicleta).',
            'nombre_plural.required' => 'Escribe el nombre en plural (ej. Bicicletas).',
            'limite_por_persona.min' => 'El límite por persona debe ser al menos 1 (o déjalo vacío para no poner límite).',
        ];
    }

    /** «Calculadora científica» → calculadora_cientifica (o calculadora_cientifica_2 si ya existe). */
    private function claveUnica(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 36, '') ?: 'recurso';
        $clave = $base;
        $n = 2;
        while ($clave === 'libro' || CategoriaRecurso::where('clave', $clave)->exists()) {
            $clave = "{$base}_{$n}";
            $n++;
        }

        return $clave;
    }
}
