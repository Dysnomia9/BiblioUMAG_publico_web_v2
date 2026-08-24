<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de datos: por cada fila existente de `libros` (que hasta ahora
 * era 1 fila = 1 copia física) crea su primer `Ejemplar` (numero_copia=1,
 * copiando las columnas físicas), migra `autor`/`categoria` (texto libre) a
 * los catálogos `autores`/`categorias` + sus pivotes, y backfillea
 * prestamos.ejemplar_id / reservas_libro.ejemplar_id a partir del ejemplar
 * recién creado (join 1:1 por libro_id, válido en este punto porque todavía
 * no existe más de un ejemplar por libro).
 *
 * Usa DB::table()/DB::statement() crudo, no Eloquent, para no depender de
 * los modelos Libro/Ejemplar que se reescriben en esta misma tanda de
 * migraciones.
 *
 * down() es un no-op deliberado: una vez que exista más de una copia de un
 * mismo título no hay forma de "aplanar" eso de vuelta a una sola fila
 * `libros` sin perder información (qué código de barras era cuál). Revertir
 * el split completo requeriría restaurar desde backup, no desde una
 * migración automática.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        DB::table('libros')->orderBy('id')->chunkById(50, function ($libros) use ($ahora) {
            foreach ($libros as $libro) {
                $ejemplarId = DB::table('ejemplares')->insertGetId([
                    'libro_id' => $libro->id,
                    'numero_copia' => 1,
                    'codigo_barras' => $libro->codigo_barras,
                    'disponible' => $libro->disponible,
                    'estado_proceso' => $libro->estado_proceso,
                    'ubicacion' => $libro->ubicacion,
                    'volumen' => $libro->volumen,
                    'precio' => $libro->precio,
                    'fecha_inventario' => $libro->fecha_inventario,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                if ($libro->autor) {
                    $autorId = $this->findOrCreate('autores', trim($libro->autor), $ahora);
                    DB::table('libro_autor')->insert([
                        'libro_id' => $libro->id,
                        'autor_id' => $autorId,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                if ($libro->categoria) {
                    $categoriaId = $this->findOrCreate('categorias', trim($libro->categoria), $ahora);
                    DB::table('libro_categoria')->insert([
                        'libro_id' => $libro->id,
                        'categoria_id' => $categoriaId,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                unset($ejemplarId);
            }
        });

        // Backfill de FK: join 1:1 por libro_id (recién creado arriba, un solo
        // ejemplar por libro en este punto de la migración).
        DB::statement('
            UPDATE prestamos SET ejemplar_id = e.id
            FROM ejemplares e
            WHERE e.libro_id = prestamos.libro_id AND prestamos.libro_id IS NOT NULL
        ');

        // 'en_cola' nunca tuvo copia física asignada — se deja ejemplar_id null a
        // propósito, igual que en el diseño nuevo de ReservaLibroService.
        DB::statement("
            UPDATE reservas_libro SET ejemplar_id = e.id
            FROM ejemplares e
            WHERE e.libro_id = reservas_libro.libro_id AND reservas_libro.estado <> 'en_cola'
        ");
    }

    public function down(): void
    {
        // No-op deliberado — ver docblock de la clase.
    }

    private function findOrCreate(string $tabla, string $nombre, $ahora): int
    {
        $existente = DB::table($tabla)->where('nombre', $nombre)->value('id');

        if ($existente) {
            return $existente;
        }

        return DB::table($tabla)->insertGetId([
            'nombre' => $nombre,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }
};
