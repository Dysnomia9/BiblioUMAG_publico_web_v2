<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FichaInterbibliotecaria;
use App\Models\Libro;
use App\Services\CatalogacionService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use App\Support\BusquedaLibros;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FichaInterbibliotecariaController extends Controller
{
    private const RELACIONES = ['libro.autores', 'libro.categorias', 'libro.carreras', 'libro.tipoMaterial', 'libro.catalogadoPor:id,nombre', 'libro.ejemplares.ubicacion'];

    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $q = FichaInterbibliotecaria::with(self::RELACIONES)->withCount([
            'ingresos', 'ingresos as pendientes_count' => fn ($p) => $p->whereNull('devuelto_el'),
        ]);
        $texto = (string) $request->query('q');
        $numero = BusquedaLibros::numeroDeFicha($texto);
        if ($numero !== null) {
            $q->whereHas('libro', fn ($l) => $l->where('numero_ficha', $numero));
        } else {
            foreach (Busqueda::palabras($texto) as $palabra) {
                $q->where(fn ($w) => $w->whereSinTildes('biblioteca', $palabra)
                    ->orWhereIn('libro_id', BusquedaLibros::ids($palabra, BusquedaLibros::CAMPOS_PERSONAL)));
            }
        }
        return response()->json($q->orderByDesc('id')->paginate(Paginacion::porPagina($request, 10, 50)));
    }

    public function show(FichaInterbibliotecaria $fichaInterbibliotecaria)
    {
        return response()->json($this->detalle($fichaInterbibliotecaria));
    }

    public function store(Request $request, CatalogacionService $catalogacion)
    {
        $data = $request->validate($this->reglas($catalogacion));
        $ficha = DB::transaction(function () use ($data, $request, $catalogacion) {
            if (! empty($data['libro_id'])) {
                $libro = Libro::whereKey($data['libro_id'])->lockForUpdate()->firstOrFail();
            } else {
                $libro = Libro::create([...$catalogacion->soloCamposBibliograficos($data['ficha']), 'catalogado_por_staff_id' => $request->user()->id]);
                $catalogacion->sincronizarPivotes($libro, $data['ficha']);
            }
            return FichaInterbibliotecaria::create([
                ...array_intersect_key($data, array_flip(['biblioteca', 'fecha_ingreso', 'fecha_limite', 'nota'])),
                'libro_id' => $libro->id, 'registrado_por_staff_id' => $request->user()->id,
            ]);
        });
        Bitacora::registrar('libros.crear_ficha_interbibliotecaria', "Creó la ficha interbibliotecaria de «{$ficha->libro->titulo}», de {$ficha->biblioteca}, sin ingresar ejemplares", entidad: $ficha);
        return response()->json($this->detalle($ficha), 201);
    }

    public function update(Request $request, FichaInterbibliotecaria $fichaInterbibliotecaria, CatalogacionService $catalogacion)
    {
        $ficha = $fichaInterbibliotecaria;
        $data = $request->validate($this->reglas($catalogacion, $ficha->libro_id));
        $antes = $ficha->only(['biblioteca', 'fecha_ingreso', 'fecha_limite', 'nota']);
        DB::transaction(function () use ($ficha, $data, $catalogacion) {
            // El orden coincide con el ingreso: primero la ficha bibliográfica, luego la recepción.
            $libro = Libro::whereKey($ficha->libro_id)->lockForUpdate()->firstOrFail();
            $ficha = FichaInterbibliotecaria::whereKey($ficha->id)->lockForUpdate()->firstOrFail();
            if (isset($data['ficha'])) {
                $libro->update($catalogacion->soloCamposBibliograficos($data['ficha']));
                $catalogacion->sincronizarPivotes($libro, $data['ficha']);
            }
            $ficha->update(array_intersect_key($data, array_flip(['biblioteca', 'fecha_ingreso', 'fecha_limite', 'nota'])));
            // Los ejemplares ya devueltos conservan las condiciones con que se devolvieron.
            // Tampoco se cambia el vencimiento de los préstamos ya hechos a usuarios.
            $ficha->ingresos()->whereNull('devuelto_el')->update($ficha->only(['biblioteca', 'fecha_ingreso', 'fecha_limite']));
        });
        $ficha->refresh();
        Bitacora::registrar('libros.editar_ficha_interbibliotecaria', "Editó la ficha interbibliotecaria de «{$ficha->libro->titulo}», de {$ficha->biblioteca}", entidad: $ficha, datos: Bitacora::cambios($antes, $ficha->only(array_keys($antes))));
        return response()->json($this->detalle($ficha));
    }

    private function detalle(FichaInterbibliotecaria $ficha): FichaInterbibliotecaria
    {
        return $ficha->load(self::RELACIONES)->loadCount(['ingresos', 'ingresos as pendientes_count' => fn ($p) => $p->whereNull('devuelto_el')]);
    }

    private function reglas(CatalogacionService $catalogacion, ?int $libroId = null): array
    {
        $reglas = [
            'libro_id' => $libroId ? ['prohibited'] : ['nullable', 'required_without:ficha', 'exists:libros,id'],
            'ficha' => $libroId ? ['required', 'array'] : ['nullable', 'required_without:libro_id', 'prohibits:libro_id', 'array'],
            'biblioteca' => ['required', 'string', 'max:255'],
            'fecha_ingreso' => ['required', 'date', 'before_or_equal:today'],
            'fecha_limite' => ['required', 'date', 'after:fecha_ingreso'],
            'nota' => ['nullable', 'string', 'max:2000'],
            // Guardar la ficha no crea ejemplares, incluso si el cliente manda campos de copia.
            'ejemplares' => ['prohibited'],
        ];
        $campos = [...Libro::CAMPOS_BIBLIOGRAFICOS, 'autores', 'autores_roles', 'categorias', 'carreras'];
        foreach ($catalogacion->reglasCatalogacion($libroId) as $campo => $regla) {
            if (in_array(explode('.', $campo)[0], $campos, true)) $reglas['ficha.'.$campo] = $regla;
        }
        $reglas['ficha.titulo'] = [$libroId ? 'required' : 'required_without:libro_id', 'nullable', 'string', 'max:1000'];
        return $reglas;
    }
}
