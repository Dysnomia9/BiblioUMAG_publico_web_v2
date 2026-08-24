<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LibroController extends Controller
{
    public function index(Request $request)
    {
        $query = Libro::query()->with(['autores', 'categorias', 'carreras', 'tipoMaterial', 'ejemplares.ubicacion'])
            ->withCount('ejemplares');

        if ($busqueda = $request->query('q')) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('titulo', 'ilike', "%{$busqueda}%")
                    ->orWhere('isbn', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('autores', fn ($a) => $a->where('nombre', 'ilike', "%{$busqueda}%"))
                    ->orWhereHas('ejemplares', fn ($e) => $e->where('codigo_barras', 'ilike', "%{$busqueda}%"));
            });
        }

        if ($categoriaId = $request->query('categoria_id')) {
            $query->whereHas('categorias', fn ($q) => $q->where('categorias.id', $categoriaId));
        }

        if ($autorId = $request->query('autor_id')) {
            $query->whereHas('autores', fn ($q) => $q->where('autores.id', $autorId));
        }

        if ($carreraId = $request->query('carrera_id')) {
            $query->whereHas('carreras', fn ($q) => $q->where('carreras.id', $carreraId));
        }

        if ($tipoMaterialId = $request->query('tipo_material_id')) {
            $query->where('tipo_material_id', $tipoMaterialId);
        }

        // Estado vive en el ejemplar (copia física), no en la obra — "buscar libros por
        // estado" devuelve los títulos que tienen al menos una copia en ese estado.
        if ($estadoProceso = $request->query('estado_proceso')) {
            $query->whereHas('ejemplares', function ($q) use ($request, $estadoProceso) {
                $q->where('estado_proceso', $estadoProceso);

                if ($estadoProceso === 'personalizado' && $estadoPersonalizadoId = $request->query('estado_personalizado_id')) {
                    $q->where('estado_personalizado_id', $estadoPersonalizadoId);
                }
            });
        }

        // Ordenar "por tipo de recurso" es alfabético por el nombre del catálogo, no por
        // el id de la FK — de ahí el join en vez de un simple orderBy('tipo_material_id').
        if ($request->query('orden') === 'tipo_material') {
            $query->leftJoin('tipos_material', 'libros.tipo_material_id', '=', 'tipos_material.id')
                ->select('libros.*')
                ->orderBy('tipos_material.nombre')
                ->orderBy('libros.titulo');
        } else {
            $query->orderBy('titulo');
        }

        return response()->json($query->get());
    }

    public function show(Libro $libro)
    {
        return response()->json(
            $libro->load(['autores', 'categorias', 'carreras', 'tipoMaterial', 'ejemplares.estadoPersonalizado', 'ejemplares.ubicacion'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglasCatalogacion());

        $libro = DB::transaction(function () use ($data) {
            $libro = Libro::create($this->soloCamposBibliograficos($data));

            $this->sincronizarPivotes($libro, $data);

            Ejemplar::create([
                'libro_id' => $libro->id,
                'numero_copia' => 1,
                'codigo_barras' => $data['codigo_barras'],
                'ubicacion_id' => $data['ubicacion_id'] ?? null,
                'volumen' => $data['volumen'] ?? null,
                'precio' => $data['precio'] ?? null,
                'estado_proceso' => 'inventario',
            ]);

            return $libro;
        });

        return response()->json($libro->load(['autores', 'categorias', 'carreras', 'tipoMaterial', 'ejemplares.ubicacion']), 201);
    }

    public function update(Request $request, Libro $libro)
    {
        $data = $request->validate($this->reglasCatalogacion($libro->id));

        DB::transaction(function () use ($libro, $data) {
            $libro->update($this->soloCamposBibliograficos($data));
            $this->sincronizarPivotes($libro, $data);
        });

        return response()->json($libro->load(['autores', 'categorias', 'carreras', 'tipoMaterial']));
    }

    /** Búsqueda por título o código de barras de cualquiera de sus copias, con historial de préstamos por ejemplar. */
    public function historial(Request $request)
    {
        $busqueda = $request->query('q');

        $query = Libro::query()->with([
            'ejemplares' => fn ($q) => $q->orderBy('numero_copia'),
            'ejemplares.prestamos' => fn ($q) => $q->with('usuario:id,nombre,apellido,rut')->orderByDesc('fecha_prestamo'),
        ]);

        if ($busqueda) {
            $query->where(function ($q) use ($busqueda) {
                $q->where('titulo', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('ejemplares', fn ($e) => $e->where('codigo_barras', 'ilike', "%{$busqueda}%"));
            });
        }

        $libros = $query->orderBy('titulo')->get()->map(function (Libro $libro) {
            $totalPrestamos = $libro->ejemplares->sum(fn ($e) => $e->prestamos->count());

            return [
                'libro' => $libro->only(['id', 'titulo', 'isbn']),
                'ejemplares' => $libro->ejemplares->map(fn (Ejemplar $e) => [
                    'id' => $e->id,
                    'numero_copia' => $e->numero_copia,
                    'codigo_barras' => $e->codigo_barras,
                    'total_prestamos' => $e->prestamos->count(),
                    'prestamos' => $e->prestamos->map(fn ($p) => [
                        'id' => $p->id,
                        'usuario' => $p->usuario ? "{$p->usuario->nombre} {$p->usuario->apellido}" : null,
                        'fecha_prestamo' => $p->fecha_prestamo,
                        'fecha_devolucion_real' => $p->fecha_devolucion_real,
                        'estado' => $p->estado,
                    ]),
                ]),
                'total_prestamos' => $totalPrestamos,
            ];
        });

        return response()->json($libros->values());
    }

    private function soloCamposBibliograficos(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'titulo', 'isbn', 'clasificacion', 'coleccion', 'editorial',
            'anio_publicacion', 'tipo_material_id', 'nota_interna', 'nota_publica',
        ]));
    }

    /** Sincroniza autores/categorías/carreras por nombre — crea el catálogo si no existe todavía (combobox "escribe y crea" del frontend). */
    private function sincronizarPivotes(Libro $libro, array $data): void
    {
        if (array_key_exists('autores', $data)) {
            $libro->autores()->sync($this->idsPorNombre(Autor::class, $data['autores'] ?? []));
        }

        if (array_key_exists('categorias', $data)) {
            $libro->categorias()->sync($this->idsPorNombre(Categoria::class, $data['categorias'] ?? []));
        }

        if (array_key_exists('carreras', $data)) {
            $libro->carreras()->sync($this->idsPorNombre(Carrera::class, $data['carreras'] ?? []));
        }
    }

    /** @return array<int> */
    private function idsPorNombre(string $modelClass, array $nombres): array
    {
        return collect($nombres)
            ->map(fn ($nombre) => trim((string) $nombre))
            ->filter()
            ->unique()
            ->map(fn ($nombre) => $modelClass::firstOrCreate(['nombre' => $nombre])->id)
            ->values()
            ->all();
    }

    private function reglasCatalogacion(?int $ignorarLibroId = null): array
    {
        return [
            'codigo_barras' => $ignorarLibroId
                ? ['sometimes']
                : ['required', 'string', 'max:255', 'unique:ejemplares,codigo_barras'],
            'titulo' => ['required', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32', 'unique:libros,isbn,' . ($ignorarLibroId ?? 'NULL') . ',id'],
            'autores' => ['nullable', 'array'],
            'autores.*' => ['string', 'max:255'],
            'categorias' => ['nullable', 'array'],
            'categorias.*' => ['string', 'max:255'],
            'carreras' => ['nullable', 'array'],
            'carreras.*' => ['string', 'max:255'],
            'clasificacion' => ['nullable', 'string', 'max:255'],
            'coleccion' => ['nullable', 'string', 'max:255'],
            'editorial' => ['nullable', 'string', 'max:255'],
            'anio_publicacion' => ['nullable', 'integer', 'min:1000', 'max:9999'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'tipo_material_id' => ['nullable', 'exists:tipos_material,id'],
            'volumen' => ['nullable', 'string', 'max:100'],
            'nota_interna' => ['nullable', 'string'],
            'nota_publica' => ['nullable', 'string'],
            'precio' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
