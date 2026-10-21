<?php

namespace App\Support;

use App\Models\Libro;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opciones de los combobox y filtros de autores, materias y áreas. Con el catálogo
 * real de Horizon son decenas de miles: en vez de la lista entera, llegan las que
 * coinciden con lo escrito (cada palabra, en cualquier orden y sin tildes) o, sin
 * escribir nada, las más usadas. Siempre a lo más LIMITE.
 */
final class OpcionesCatalogo
{
    public const LIMITE = 20;

    /**
     * @param  class-string<Model>  $modelo  con columna nombre y relación libros (Autor, Categoria)
     * @param  (Closure(Builder): mixed)|null  $libros  solo las que tienen algún libro así
     * @return list<array{id: int, nombre: string}>
     */
    public static function buscar(string $modelo, mixed $texto, ?Closure $libros = null): array
    {
        $texto = trim((string) $texto);

        $opciones = $texto !== ''
            ? $modelo::query()
                ->whereCadaPalabra('nombre', $texto)
                ->when($libros !== null, fn (Builder $q) => $q->whereHas('libros', $libros))
                ->orderBy('nombre')
                ->limit(self::LIMITE)
                ->get(['id', 'nombre'])
            : self::masUsadas($modelo, $libros);

        return $opciones->map(fn (Model $opcion) => ['id' => $opcion->id, 'nombre' => $opcion->nombre])->values()->all();
    }

    /**
     * Las de más libros, contadas de una vez en la tabla que las une con los libros
     * (libro_autor, libro_categoria): contar los libros de cada una por separado
     * tardaba medio segundo con 20.000 autores.
     */
    private static function masUsadas(string $modelo, ?Closure $libros)
    {
        $relacion = (new $modelo)->libros();
        $clave = $relacion->getForeignPivotKeyName();

        $ids = DB::table($relacion->getTable())
            ->when($libros !== null, fn ($q) => $q->whereIn($relacion->getRelatedPivotKeyName(), tap(Libro::query(), $libros)->select('id')))
            ->groupBy($clave)
            ->orderByRaw('count(*) desc')
            ->orderBy($clave)
            ->limit(self::LIMITE)
            ->pluck($clave);

        return $modelo::whereKey($ids)->get(['id', 'nombre'])->sortBy(fn (Model $opcion) => $ids->search($opcion->id));
    }
}
