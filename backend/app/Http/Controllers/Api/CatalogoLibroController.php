<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Usuario;
use App\Support\Materia;
use App\Support\OpcionesCatalogo;
use App\Support\Texto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listas de catálogo para los combobox y filtros de libros. Autores y categorías se
 * buscan por lo que se escribe (?q=) y llegan de a pocas (ver OpcionesCatalogo); las
 * carreras son pocas (unos cientos) y van completas.
 */
class CatalogoLibroController extends Controller
{
    public function autores(Request $request)
    {
        return response()->json(OpcionesCatalogo::buscar(Autor::class, $request->query('q'), conLibros: $request->boolean('con_libros')));
    }

    public function categorias(Request $request)
    {
        // «mapuches--his» sugiere «MAPUCHES -- HISTORIA»: el texto con las subdivisiones ordenadas
        return response()->json(OpcionesCatalogo::buscar(Categoria::class, Materia::normalizar($request->query('q')), conLibros: $request->boolean('con_libros')));
    }

    /**
     * Sugerencias para la entidad (110) y la entidad relacionada (710) al catalogar: las
     * entidades ya escritas en otras fichas y los autores registrados, como sugiere el 100.
     * El 710 puede traer varias separadas por «;»: cada una cuenta por separado. Solo con
     * algo escrito (cada palabra, sin tildes) y a lo más OpcionesCatalogo::LIMITE, primero
     * las de más fichas. Si una está en ambas listas, queda una sola.
     */
    public function entidades(Request $request)
    {
        $texto = trim((string) $request->query('q'));
        if ($texto === '') {
            return response()->json([]);
        }

        $escritas = DB::table('libros')->select('entidad_corporativa as nombre')->where('entidad_corporativa', '<>', '')
            ->unionAll(DB::table('libros')->selectRaw("trim(regexp_split_to_table(entidad_secundaria, ';')) as nombre")->where('entidad_secundaria', '<>', ''));

        $entidades = DB::query()->fromSub($escritas, 'e')
            ->selectRaw('nombre, count(*) as libros')
            ->where('nombre', '<>', '')
            ->whereCadaPalabra('nombre', $texto)
            ->groupBy('nombre')
            ->orderByDesc('libros')
            ->orderBy('nombre')
            ->limit(OpcionesCatalogo::LIMITE)
            ->get()
            ->map(fn ($e) => ['nombre' => $e->nombre, 'libros' => (int) $e->libros]);

        $autores = collect(OpcionesCatalogo::buscar(Autor::class, $texto, conLibros: true))
            ->map(fn (array $a) => ['nombre' => $a['nombre'], 'libros' => $a['libros'] ?? 0]);

        return response()->json($entidades->concat($autores)
            ->unique(fn (array $o) => Texto::clave($o['nombre']))
            ->sortBy([['libros', 'desc'], ['nombre', 'asc']])
            ->take(OpcionesCatalogo::LIMITE)
            ->values());
    }

    /**
     * Cómo quedan, frente al catálogo, las materias que se están poniendo a un libro, para
     * avisar al catalogar antes de crear una repetida. Por cada nombre:
     * - «exacta»: ya existe escrita igual;
     * - «igual»: existe con otras mayúsculas o tildes; al guardar se usa la existente
     *   (LibroController::idsPorNombre), así que solo se avisa cómo quedará;
     * - «parecida»: existe con otros espacios o signos («RAPANUI» y «RAPA NUI»); al guardar
     *   se crearía otra, así que se ofrece usar la existente;
     * - «nueva»: no hay ninguna parecida; se creará al guardar. Si su tema (lo de antes del
     *   primer « -- ») existe escrito de otra forma, «sugerencia» trae la materia con ese tema.
     */
    public function revisarMaterias(Request $request)
    {
        $nombres = collect($request->validate([
            'nombres' => ['required', 'array', 'max:50'],
            'nombres.*' => ['string', 'max:255'],
        ])['nombres'])->map(fn ($n) => Materia::normalizar($n))->filter()->unique()->values();

        return response()->json($nombres->map(function (string $nombre) {
            [$tipo, $existente] = $this->materiaParecida($nombre);
            $sugerencia = null;

            // «RAPANUI -- LENGUAS -- DICCIONARIOS» no existe entera, pero su tema sí («RAPA NUI»):
            // se propone escribirla con el tema como ya está en el catálogo.
            $partes = explode(' -- ', $nombre, 2);
            if ($tipo === 'nueva' && count($partes) === 2) {
                [$tipoTema, $tema] = $this->materiaParecida($partes[0]);
                if ($tema && $tipoTema !== 'exacta') {
                    $sugerencia = ['tema' => $partes[0], 'tema_existente' => $tema['nombre'], 'nombre' => "{$tema['nombre']} -- {$partes[1]}"];
                }
            }

            return ['nombre' => $nombre, 'tipo' => $tipo, 'existente' => $existente, 'sugerencia' => $sugerencia];
        }));
    }

    /**
     * La materia del catálogo igual a $nombre salvo mayúsculas, tildes, espacios o signos
     * (entre varias, la que tiene más libros) y qué tan igual es. Usa la clave con que
     * LibroController::idsPorNombre() decide si ya existe (la ñ no se iguala a la n).
     *
     * @return array{0: 'exacta'|'igual'|'parecida'|'nueva', 1: array{id: int, nombre: string, libros: int}|null}
     */
    private function materiaParecida(string $nombre): array
    {
        $compacta = preg_replace('/[^a-z0-9ñ]/u', '', LibroController::claveNombre($nombre));
        if ($compacta === '') {
            return ['nueva', null];
        }

        $parecida = Categoria::query()
            ->whereRaw("regexp_replace(translate(lower(nombre), ?, ?), '[^a-z0-9ñ]', '', 'g') = ?", [LibroController::CON_TILDE, LibroController::SIN_TILDE, $compacta])
            ->withCount('libros')
            ->orderByDesc('libros_count')
            ->orderBy('id')
            ->first(['id', 'nombre']);

        $tipo = match (true) {
            ! $parecida => 'nueva',
            $parecida->nombre === $nombre => 'exacta',
            LibroController::claveNombre($parecida->nombre) === LibroController::claveNombre($nombre) => 'igual',
            default => 'parecida',
        };

        return [$tipo, $parecida ? ['id' => $parecida->id, 'nombre' => $parecida->nombre, 'libros' => $parecida->libros_count] : null];
    }

    /**
     * Las carreras del catálogo de libros (los filtros buscan por su id). Con ?con_usuarios=1,
     * también las que tienen los usuarios y todavía ningún libro, sin id: son las sugerencias
     * de «Carreras asociadas» al catalogar, y entran al catálogo cuando se asignan a un libro
     * (LibroController::sincronizarPivotes). Una carrera escrita con otras tildes o
     * mayúsculas no se repite; entre las de usuarios gana la que trae minúsculas.
     */
    public function carreras(Request $request)
    {
        $catalogo = Carrera::orderBy('nombre')->get(['id', 'nombre']);
        if (! $request->boolean('con_usuarios')) {
            return response()->json($catalogo);
        }

        $porClave = $catalogo->mapWithKeys(fn (Carrera $c) => [Texto::clave($c->nombre) => ['id' => $c->id, 'nombre' => $c->nombre]]);
        foreach (Usuario::whereNotNull('carrera')->distinct()->pluck('carrera') as $nombre) {
            $nombre = trim($nombre);
            $clave = Texto::clave($nombre);
            $actual = $porClave->get($clave);
            if ($nombre !== '' && ($actual === null || ($actual['id'] === null && ! preg_match('/\p{Ll}/u', $actual['nombre']) && preg_match('/\p{Ll}/u', $nombre)))) {
                $porClave->put($clave, ['id' => null, 'nombre' => $nombre]);
            }
        }

        return response()->json($porClave->sortKeys()->values());
    }
}
