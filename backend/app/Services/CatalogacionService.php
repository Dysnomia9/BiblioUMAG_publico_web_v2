<?php

namespace App\Services;

use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\Ejemplar;
use App\Models\Libro;
use App\Support\Materia;
use Illuminate\Validation\Rule;

/** Reglas y relaciones compartidas de la ficha bibliográfica, también para material de otras bibliotecas. */
class CatalogacionService
{
    public function soloCamposBibliograficos(array $data): array
    {
        return array_intersect_key($data, array_flip(Libro::CAMPOS_BIBLIOGRAFICOS));
    }

    /** Sincroniza autores/categorías/carreras por nombre — crea el catálogo si no existe todavía (combobox "escribe y crea" del frontend). */
    public function sincronizarPivotes(Libro $libro, array $data): void
    {
        if (array_key_exists('autores', $data)) {
            // Se guarda el orden: el primero es el autor principal (100).
            // El rol de cada uno va en autores_roles, en el mismo orden (null = autor).
            // Pares [nombre, rol] sin vacíos ni repetidos (el mismo criterio que idsPorNombre),
            // para que cada id quede con el rol de su nombre.
            $roles = array_values($data['autores_roles'] ?? []);
            $pares = collect($data['autores'] ?? [])->values()
                ->map(fn ($n, $i) => [trim(preg_replace('/\s+/u', ' ', (string) $n)), $roles[$i] ?? null])
                ->filter(fn ($p) => $p[0] !== '')
                ->unique(fn ($p) => self::claveNombre($p[0]))
                ->values();
            $ids = $this->idsPorNombre(Autor::class, $pares->pluck(0)->all());
            $libro->autores()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => [
                'orden' => $i + 1,
                'rol' => $pares[$i][1] ?? null,
            ]])->all());
        }

        if (array_key_exists('categorias', $data)) {
            $libro->categorias()->sync($this->idsPorNombre(Categoria::class, $data['categorias'] ?? []));
        }

        if (array_key_exists('carreras', $data)) {
            $libro->carreras()->sync($this->idsPorNombre(Carrera::class, $data['carreras'] ?? []));
        }
    }

    public const CON_TILDE = 'áéíóúàèìòùäëïöüâêîôû';

    public const SIN_TILDE = 'aeiouaeiouaeiouaeiou';

    /** Cómo se comparan los nombres: en minúsculas y sin tildes (la ñ se mantiene). */
    public static function claveNombre(string $nombre): string
    {
        $base = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)));

        return strtr($base, array_combine(mb_str_split(self::CON_TILDE), mb_str_split(self::SIN_TILDE)));
    }

    /** @return array<int> */
    private function idsPorNombre(string $modelClass, array $nombres): array
    {
        // Un solo espacio entre palabras («Mapuche  -  Lenguas» → «Mapuche - Lenguas») y, si
        // ya existe escrita igual salvo mayúsculas, tildes o espacios («COMUNICACION» y
        // «Comunicación»), se usa esa en vez de crear otra: así no se repiten materias ni
        // autores como pasaba en el sistema anterior. La ñ no se iguala a la n (año ≠ ano).
        return collect($nombres)
            ->map(fn ($nombre) => trim(preg_replace('/\s+/u', ' ', (string) $nombre)))
            // Las materias, además, con sus subdivisiones escritas siempre igual (« -- »)
            ->map(fn ($nombre) => $modelClass === Categoria::class ? Materia::normalizar($nombre) : $nombre)
            ->filter()
            ->unique(fn ($nombre) => self::claveNombre($nombre))
            ->map(fn ($nombre) => $modelClass::query()
                ->whereRaw("translate(lower(regexp_replace(trim(nombre), '\\s+', ' ', 'g')), ?, ?) = ?", [self::CON_TILDE, self::SIN_TILDE, self::claveNombre($nombre)])
                ->orderBy('id')
                ->value('id') ?? $modelClass::create(['nombre' => $nombre])->id)
            ->values()
            ->all();
    }

    public function reglasCatalogacion(?int $ignorarLibroId = null): array
    {
        return [
            'codigo_barras' => $ignorarLibroId
                ? ['sometimes']
                // Sin código se crea solo la ficha (el registro bibliográfico, como en Horizon);
                // las copias se agregan después desde «Nuevo ejemplar».
                : ['nullable', 'string', 'max:255', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo', 'unique:equipos,codigo_barras'],
            // Opcional: sin título la ficha queda como «Sin título» (Libro::SIN_TITULO).
            'titulo' => ['nullable', 'string', 'max:1000'],
            'isbn' => ['nullable', 'string', 'max:32', 'unique:libros,isbn,' . ($ignorarLibroId ?? 'NULL') . ',id'],
            // Varios, separados por «;», y se pueden repetir entre títulos (el de la colección)
            'otros_isbn' => ['nullable', 'string', 'max:500'],
            'autores' => ['nullable', 'array'],
            'autores.*' => ['string', 'max:255'],
            'autores_roles' => ['nullable', 'array'],
            'autores_roles.*' => ['nullable', 'string', Rule::exists('roles_autor', 'clave')],
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
            'titulo_alternativo' => ['nullable', 'string', 'max:2000'], // 246 repetible: uno por línea
            'mencion_responsabilidad' => ['nullable', 'string', 'max:2000'], // 245 $c
            'edicion' => ['nullable', 'string', 'max:255'],
            'lugar_publicacion' => ['nullable', 'string', 'max:255'],
            'descripcion_fisica' => ['nullable', 'string', 'max:2000'],
            'entidad_corporativa' => ['nullable', 'string', 'max:255'],
            'reunion' => ['nullable', 'string', 'max:255'],
            'entidad_secundaria' => ['nullable', 'string', 'max:255'],
            'materias_personales' => ['nullable', 'string'],
            'materias_geograficas' => ['nullable', 'string'],
            'url_acceso' => ['nullable', 'url', 'max:2048'],
            // Los demás 856, uno por línea: «URL» o «URL texto»
            'otros_enlaces' => ['nullable', 'string', 'max:10000', function (string $campo, mixed $valor, \Closure $falla) {
                foreach (preg_split('/\R/', (string) $valor) as $linea) {
                    $url = strtok(trim($linea), ' ');
                    if ($url !== false && trim($linea) !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                        $falla("Cada línea de «Otros enlaces» empieza con una dirección web (https://…): «{$url}» no lo es.");
                    }
                }
            }],
            'issn' => ['nullable', 'string', 'max:32'],
            'designacion_volumen' => ['nullable', 'string', 'max:255'],
            'nota_tesis' => ['nullable', 'string'],
            'profesor_guia' => ['nullable', 'string', 'max:255'],
            'resumen' => ['nullable', 'string'],
            'audiencia' => ['nullable', 'string', 'max:255'],
            'idioma' => ['nullable', 'string', 'max:100'],
            'serie' => ['nullable', 'string', 'max:2000'], // 490 repetible: una por línea
            'nota_bibliografia' => ['nullable', 'string'],
            'frecuencia' => ['nullable', 'string', 'max:100'],
            'nota_idioma' => ['nullable', 'string', 'max:255'],
            'nota_local' => ['nullable', 'string'],
            'contenido' => ['nullable', 'string'],
        ];
    }
}
