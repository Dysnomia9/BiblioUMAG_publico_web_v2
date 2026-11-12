<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. El título pasa a texto: algunos de Horizon (mapas antiguos, informes) pasan de 255
 *    caracteres y quedaban cortados con «…».
 * 2. Horizon guardaba algunos caracteres como texto «<U+00AA>» (ª, º, ´, –, ™…) y así se
 *    traspasaron: se cambian por el carácter real en libros, autores y materias.
 */
return new class extends Migration
{
    private const TABLAS = ['libros', 'autores', 'categorias'];

    public function up(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN titulo TYPE text');

        foreach (self::TABLAS as $tabla) {
            $columnas = DB::table('information_schema.columns')
                ->where('table_schema', 'public')->where('table_name', $tabla)
                ->whereIn('data_type', ['text', 'character varying'])
                ->pluck('column_name');

            foreach ($columnas as $columna) {
                $codigos = DB::table($tabla)
                    ->selectRaw("distinct (regexp_matches({$columna}, '<U\\+([0-9A-Fa-f]{4})>', 'g'))[1] as codigo")
                    ->pluck('codigo');

                foreach ($codigos as $codigo) {
                    DB::table($tabla)
                        ->where($columna, 'like', "%<U+{$codigo}>%")
                        ->update([$columna => DB::raw("replace({$columna}, '<U+{$codigo}>', chr(".hexdec($codigo).'))')]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE libros ALTER COLUMN titulo TYPE varchar(255) USING left(titulo, 255)');
    }
};
