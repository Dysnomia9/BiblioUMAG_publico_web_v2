<?php

use App\Support\Busqueda;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Índices de trigramas (pg_trgm) para las búsquedas de texto parcial: «calculo» en
     * medio de un título, parte de un RUT o de un código de barras. Sin ellos, cada
     * búsqueda revisaba todas las filas (con 20.000 títulos, ~0,9 s por tecla).
     *
     * Las expresiones son exactamente las que arman las consultas (Busqueda::sinTildes),
     * para que Postgres las reconozca. pg_trgm viene con Postgres y desde la versión 13
     * la puede activar el dueño de la base; si el usuario no tiene ese permiso, la
     * migración sigue sin los índices (las búsquedas funcionan igual, solo más lentas)
     * y lo deja anotado en el log.
     */
    private function indices(): array
    {
        return [
            ['libros', Busqueda::sinTildes('titulo'), 'libros_titulo_trgm'],
            ['libros', Busqueda::sinTildes('titulo_alternativo'), 'libros_titulo_alternativo_trgm'],
            ['libros', 'clasificacion', 'libros_clasificacion_trgm'],
            ['libros', 'isbn', 'libros_isbn_trgm'],
            ['libros', "replace(isbn, '-', '')", 'libros_isbn_sin_guiones_trgm'],
            ['autores', Busqueda::sinTildes('nombre'), 'autores_nombre_trgm'],
            ['categorias', Busqueda::sinTildes('nombre'), 'categorias_nombre_trgm'],
            ['ejemplares', 'codigo_barras', 'ejemplares_codigo_barras_trgm'],
            ['ejemplares', 'codigo_lomo', 'ejemplares_codigo_lomo_trgm'],
            ['prestamos', Busqueda::sinTildes('libro_titulo'), 'prestamos_libro_titulo_trgm'],
            ['prestamos', 'codigo_barras', 'prestamos_codigo_barras_trgm'],
            ['usuarios', Busqueda::sinTildes("(nombre || ' ' || apellido)"), 'usuarios_nombre_completo_trgm'],
            ['usuarios', 'rut', 'usuarios_rut_trgm'],
        ];
    }

    public function up(): void
    {
        try {
            // Punto de guardado: si falla, la migración sigue (Postgres anula la transacción entera si no).
            DB::transaction(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm'));
        } catch (\Throwable $e) {
            Log::warning('No se pudo activar pg_trgm: las búsquedas de texto quedan sin índice. '.$e->getMessage());

            return;
        }

        foreach ($this->indices() as [$tabla, $expresion, $nombre]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$nombre} ON {$tabla} USING gin (({$expresion}) gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach ($this->indices() as [, , $nombre]) {
            DB::statement("DROP INDEX IF EXISTS {$nombre}");
        }
    }
};
