<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Models\ReservaLibro;
use App\Services\BajaEjemplarService;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\Bitacora;
use App\Support\FichaBibliografica;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LibroController extends Controller
{
    private const RELACIONES = ['autores', 'categorias', 'carreras', 'tipoMaterial', 'catalogadoPor:id,nombre'];

    public function index(Request $request)
    {
        $query = Libro::query()->with([
            ...self::RELACIONES,
            // Cada copia dice si está prestada o reservada (listado de libros y estado de ejemplares).
            'ejemplares' => fn ($q) => $q->orderBy('numero_copia')->withExists([
                'prestamos as prestado' => fn ($p) => $p->where('estado', '!=', 'devuelto'),
                'reservasLibro as reservado' => fn ($r) => $r->where('estado', 'pendiente'),
            ]),
            'ejemplares.ubicacion',
            'ejemplares.estadoPersonalizado',
        ])->withCount('ejemplares');

        // Cada palabra tiene que aparecer en algún campo, en cualquier orden (ver Busqueda::palabras()):
        // título, clasificación, ISBN/ISSN, autor o código de una copia (ver BusquedaLibros).
        foreach (Busqueda::palabras((string) $request->query('q')) as $palabra) {
            $query->whereIn('libros.id', BusquedaLibros::ids($palabra, BusquedaLibros::CAMPOS_PERSONAL));
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

        // Por páginas: el catálogo real son decenas de miles de títulos, cada uno con su
        // ficha y sus copias. La exportación pide páginas grandes (hasta 500) una tras otra.
        return response()->json(
            $query->orderBy('libros.id')->paginate(Paginacion::porPagina($request))->through(fn (Libro $libro) => $this->conFicha($libro))
        );
    }

    public function show(Libro $libro)
    {
        return response()->json(
            $this->conFicha($libro->load([...self::RELACIONES, 'ejemplares.estadoPersonalizado', 'ejemplares.ubicacion']))
        );
    }

    /** Plantillas de ficha por tipo de material, para armar el formulario de catalogación. */
    public function plantillas()
    {
        return response()->json(FichaBibliografica::plantillas());
    }

    /**
     * Da de baja un título: todas sus copias salen de circulación, las prestadas incluidas
     * (se perdieron), y las personas en su lista de espera quedan sin reserva. Pide el
     * motivo y que se escriba CONFIRMAR. Si una copia está apartada para quien va a
     * retirarla, no se hace nada hasta cancelar esa reserva.
     */
    public function darDeBaja(Request $request, Libro $libro, BajaEjemplarService $bajas)
    {
        $data = $request->validate(
            ['motivo' => ['required', 'string', 'max:500'], 'confirmacion' => ['required', Rule::in(['CONFIRMAR'])]],
            ['motivo.required' => 'Indica el motivo de la baja: queda registrado en el historial.', 'confirmacion.required' => 'Escribe CONFIRMAR para dar de baja el título.', 'confirmacion.in' => 'Escribe CONFIRMAR para dar de baja el título.'],
        );

        $resultado = DB::transaction(function () use ($libro, $data, $request, $bajas) {
            $copias = Ejemplar::where('libro_id', $libro->id)->where('estado_proceso', '!=', 'de_baja')->orderBy('id')->lockForUpdate()->get();

            if ($copias->isEmpty()) {
                return ['error' => 'Este título no tiene copias en circulación: ya está dado de baja.'];
            }

            foreach ($copias as $copia) {
                if ($bloqueo = $bajas->bloqueo($copia)) {
                    return ['error' => "La copia {$copia->numero_copia} ({$copia->codigo_barras}): {$bloqueo}"];
                }
            }

            $loteId = (string) \Illuminate\Support\Str::uuid();
            $prestadas = 0;
            foreach ($copias as $copia) {
                if ($bajas->darDeBaja($copia, $data['motivo'], $request->user(), $loteId)) {
                    $prestadas++;
                }
            }

            $enCola = ReservaLibro::where('libro_id', $libro->id)->where('estado', 'en_cola')->update(['estado' => 'cancelado']);

            return ['copias' => $copias->count(), 'prestadas' => $prestadas, 'en_cola' => $enCola, 'lote_id' => $loteId];
        });

        if (isset($resultado['error'])) {
            return response()->json(['message' => $resultado['error']], 409);
        }

        Bitacora::registrar(
            'libros.dar_de_baja',
            "Dio de baja el título «{$libro->titulo}» ({$resultado['copias']} ".($resultado['copias'] === 1 ? 'copia' : 'copias').')'
                .($resultado['prestadas'] ? ", {$resultado['prestadas']} estaban prestadas y sus préstamos quedaron cerrados" : '')
                .($resultado['en_cola'] ? ", {$resultado['en_cola']} reservas en espera canceladas" : '')
                .". Motivo: {$data['motivo']}",
            entidad: $libro,
            datos: [...$resultado, 'motivo' => $data['motivo']],
        );

        return response()->json($resultado);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglasCatalogacion());

        $libro = DB::transaction(function () use ($data, $request) {
            $libro = Libro::create([
                ...$this->soloCamposBibliograficos($data),
                'catalogado_por_staff_id' => $request->user()->id,
            ]);

            $this->sincronizarPivotes($libro, $data);

            Ejemplar::create([
                'libro_id' => $libro->id,
                'numero_copia' => 1,
                'codigo_barras' => $data['codigo_barras'],
                'ubicacion_id' => $data['ubicacion_id'] ?? null,
                'volumen' => $data['volumen'] ?? null,
                'precio' => $data['precio'] ?? null,
                'forma_adquisicion' => $data['forma_adquisicion'] ?? null,
                'nota_publica' => $data['copia_nota_publica'] ?? null,
                'nota_interna' => $data['copia_nota_interna'] ?? null,
                'estado_proceso' => 'inventario',
            ]);

            return $libro;
        });

        Bitacora::registrar(
            'libros.catalogar',
            "Catalogó «{$libro->titulo}» con su primera copia ({$data['codigo_barras']})",
            entidad: $libro,
            datos: ['codigo_barras' => $data['codigo_barras'], 'isbn' => $libro->isbn],
        );

        return response()->json($this->conFicha($libro->load([...self::RELACIONES, 'ejemplares.ubicacion'])), 201);
    }

    public function update(Request $request, Libro $libro)
    {
        $data = $request->validate($this->reglasCatalogacion($libro->id));

        $antes = $libro->only(Libro::CAMPOS_BIBLIOGRAFICOS);

        DB::transaction(function () use ($libro, $data) {
            $libro->update($this->soloCamposBibliograficos($data));
            $this->sincronizarPivotes($libro, $data);
        });

        Bitacora::registrar(
            'libros.editar',
            "Editó la ficha de «{$libro->titulo}»",
            entidad: $libro,
            datos: Bitacora::cambios($antes, $libro->only(Libro::CAMPOS_BIBLIOGRAFICOS)),
        );

        return response()->json($this->conFicha($libro->load(self::RELACIONES)));
    }

    /** El personal ve la ficha completa, con los campos de uso interno marcados. */
    private function conFicha(Libro $libro): Libro
    {
        return $libro->setAttribute('ficha', FichaBibliografica::ficha($libro, conInternos: true));
    }

    /**
     * Historial de libros: la lista, por páginas, con cuántas copias y cuántos préstamos
     * tiene cada título (contados en la base). El detalle de los préstamos de un título
     * se pide aparte (historialDeLibro), solo del que se elige.
     */
    public function historial(Request $request)
    {
        $busqueda = $request->query('q');

        $query = Libro::query()
            ->select(['id', 'titulo', 'isbn', 'clasificacion'])
            ->withCount(['ejemplares as copias', 'prestamos as total_prestamos']);

        if ($busqueda) {
            $query->whereIn('libros.id', BusquedaLibros::ids($busqueda, ['titulo', 'isbn', 'codigos']));
        }

        return response()->json(
            $query->orderBy('titulo')->orderBy('id')->paginate(Paginacion::porPagina($request, 30, 200))->through(fn (Libro $libro) => [
                'libro' => $libro->only(['id', 'titulo', 'isbn', 'clasificacion']),
                'copias' => $libro->copias,
                'total_prestamos' => $libro->total_prestamos,
            ])
        );
    }

    /** Todos los préstamos de un título, copia por copia (un solo libro: siempre acotado). */
    public function historialDeLibro(Libro $libro)
    {
        $libro->load([
            'ejemplares' => fn ($q) => $q->orderBy('numero_copia'),
            'ejemplares.prestamos' => fn ($q) => $q->with('usuario:id,nombre,apellido,rut')->orderByDesc('fecha_prestamo'),
        ]);

        return response()->json([
            'libro' => $libro->only(['id', 'titulo', 'isbn', 'clasificacion']),
            'ejemplares' => $libro->ejemplares->map(fn (Ejemplar $e) => [
                'id' => $e->id,
                'numero_copia' => $e->numero_copia,
                'codigo_barras' => $e->codigo_barras,
                'total_prestamos' => $e->prestamos->count(),
                'prestamos' => $e->prestamos->map(fn ($p) => [
                    'id' => $p->id,
                    'usuario' => $p->usuario ? "{$p->usuario->nombre} {$p->usuario->apellido}" : null,
                    'fecha_prestamo' => $p->fecha_prestamo,
                    'fecha_devolucion' => $p->fecha_devolucion,
                    'fecha_devolucion_real' => $p->fecha_devolucion_real,
                    'estado' => $p->estado,
                ]),
            ]),
            'total_prestamos' => $libro->ejemplares->sum(fn ($e) => $e->prestamos->count()),
        ]);
    }

    private function soloCamposBibliograficos(array $data): array
    {
        return array_intersect_key($data, array_flip(Libro::CAMPOS_BIBLIOGRAFICOS));
    }

    /** Sincroniza autores/categorías/carreras por nombre — crea el catálogo si no existe todavía (combobox "escribe y crea" del frontend). */
    private function sincronizarPivotes(Libro $libro, array $data): void
    {
        if (array_key_exists('autores', $data)) {
            // Se guarda el orden: el primero es el autor principal (100).
            $ids = $this->idsPorNombre(Autor::class, $data['autores'] ?? []);
            $libro->autores()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['orden' => $i + 1]])->all());
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
                : ['required', 'string', 'max:255', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo'],
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
            // Notas de la primera copia, que se crea junto con el libro.
            'copia_nota_publica' => ['nullable', 'string', 'max:5000'],
            'copia_nota_interna' => ['nullable', 'string', 'max:5000'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'forma_adquisicion' => ['nullable', Rule::in(Ejemplar::FORMAS_ADQUISICION)],
            'titulo_alternativo' => ['nullable', 'string', 'max:255'],
            'edicion' => ['nullable', 'string', 'max:255'],
            'lugar_publicacion' => ['nullable', 'string', 'max:255'],
            'descripcion_fisica' => ['nullable', 'string', 'max:255'],
            'entidad_corporativa' => ['nullable', 'string', 'max:255'],
            'reunion' => ['nullable', 'string', 'max:255'],
            'entidad_secundaria' => ['nullable', 'string', 'max:255'],
            'materias_personales' => ['nullable', 'string'],
            'materias_geograficas' => ['nullable', 'string'],
            'url_acceso' => ['nullable', 'url', 'max:2048'],
            'issn' => ['nullable', 'string', 'max:32'],
            'designacion_volumen' => ['nullable', 'string', 'max:255'],
            'nota_tesis' => ['nullable', 'string'],
            'profesor_guia' => ['nullable', 'string', 'max:255'],
            'resumen' => ['nullable', 'string'],
            'audiencia' => ['nullable', 'string', 'max:255'],
            'idioma' => ['nullable', 'string', 'max:100'],
            'serie' => ['nullable', 'string', 'max:255'],
            'nota_bibliografia' => ['nullable', 'string'],
            'frecuencia' => ['nullable', 'string', 'max:100'],
            'nota_idioma' => ['nullable', 'string', 'max:255'],
            'nota_local' => ['nullable', 'string'],
            'contenido' => ['nullable', 'string'],
        ];
    }
}
