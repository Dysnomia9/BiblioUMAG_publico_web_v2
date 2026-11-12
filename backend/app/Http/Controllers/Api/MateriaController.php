<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Support\Bitacora;
use App\Support\Materia;
use App\Support\Busqueda;
use App\Support\Paginacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mantenedor de materias (tabla categorias) en Administración → Catálogos: buscarlas,
 * renombrarlas, unir dos que son la misma y eliminar las que ningún libro usa. Los libros
 * están enlazados por id, así que al renombrar siguen teniendo la materia.
 */
class MateriaController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'in:nombre,libros'],
            'sin_libros' => ['nullable', 'boolean'],
        ]);
        $q = trim((string) $request->query('q'));

        $pagina = Categoria::query()
            ->withCount('libros')
            ->when($q !== '', fn ($w) => $w->whereRaw(Busqueda::sinTildes('nombre').' ilike ?', [Busqueda::patron($q)]))
            ->when($request->boolean('sin_libros'), fn ($w) => $w->doesntHave('libros'))
            ->when($request->query('orden') === 'libros', fn ($w) => $w->orderByDesc('libros_count'))
            ->orderBy('nombre')
            ->paginate(Paginacion::porPagina($request, 50, 200));

        return response()->json($pagina);
    }

    /**
     * Renombra. Si ya hay otra materia escrita igual (sin distinguir mayúsculas, tildes ni
     * espacios), responde 409 con cuál es; con unir=true pasa sus libros a esa y borra esta.
     */
    public function update(Request $request, Categoria $categoria)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'unir' => ['nullable', 'boolean'],
        ]);
        $nombre = Materia::normalizar($data['nombre']);
        $antes = $categoria->nombre;

        $otra = Categoria::where('id', '!=', $categoria->id)
            ->whereRaw("translate(lower(regexp_replace(trim(nombre), '\\s+', ' ', 'g')), ?, ?) = ?", [
                LibroController::CON_TILDE, LibroController::SIN_TILDE, LibroController::claveNombre($nombre),
            ])
            ->first();

        if ($otra && ! ($data['unir'] ?? false)) {
            return response()->json([
                'message' => "Ya existe la materia «{$otra->nombre}». ¿Unir «{$antes}» con ella? Sus libros pasarán a «{$otra->nombre}».",
                'existente' => $otra->loadCount('libros'),
            ], 409);
        }

        if ($otra) {
            $movidos = $this->unir($categoria, $otra);
            Bitacora::registrar('catalogo.unir_materia', "Unió la materia «{$antes}» con «{$otra->nombre}» ({$movidos} libros)", entidad: $otra,
                datos: ['desde' => $antes, 'hacia' => $otra->nombre, 'libros' => $movidos]);

            return response()->json(['unida_con' => $otra->loadCount('libros')]);
        }

        $categoria->update(['nombre' => $nombre]);
        Bitacora::registrar('catalogo.editar_materia', "Cambió el nombre de la materia «{$antes}» a «{$nombre}»", entidad: $categoria,
            datos: ['nombre' => [$antes, $nombre]]);

        return response()->json($categoria->loadCount('libros'));
    }

    /** Solo se elimina una materia que ningún libro usa. */
    public function destroy(Categoria $categoria)
    {
        $libros = $categoria->libros()->count();
        if ($libros) {
            return response()->json(['message' => "«{$categoria->nombre}» está en {$libros} ".($libros === 1 ? 'libro' : 'libros').': renómbrala o únela con otra en vez de eliminarla.'], 409);
        }

        $nombre = $categoria->nombre;
        $categoria->delete();
        Bitacora::registrar('catalogo.eliminar_materia', "Eliminó la materia «{$nombre}», que no tenía libros");

        return response()->noContent();
    }

    /** Pasa los libros de $desde a $hacia (sin repetirlos) y borra $desde. Devuelve cuántos libros tenía. */
    private function unir(Categoria $desde, Categoria $hacia): int
    {
        return DB::transaction(function () use ($desde, $hacia) {
            $movidos = DB::table('libro_categoria')->where('categoria_id', $desde->id)->count();
            DB::table('libro_categoria as v')
                ->where('v.categoria_id', $desde->id)
                ->whereExists(fn ($q) => $q->from('libro_categoria as n')->whereColumn('n.libro_id', 'v.libro_id')->where('n.categoria_id', $hacia->id))
                ->delete();
            DB::table('libro_categoria')->where('categoria_id', $desde->id)->update(['categoria_id' => $hacia->id]);
            $desde->delete();

            return $movidos;
        });
    }
}
