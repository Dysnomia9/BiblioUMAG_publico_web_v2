<?php

namespace App\Support;

use App\Models\Libro;

/**
 * Si la mención de responsabilidad (245 $c) dice algo que la ficha no muestra ya. Casi
 * siempre repite a los autores del 100/700 («Celina Carrasco, Jesús Donari»): entonces no
 * se muestra. Solo se muestra si nombra a alguien que no está catalogado, como la segunda
 * autora del bib# 56012 («Margot C. Howe, Shara L. Schwartzberg», sin un 700).
 * Se sigue guardando y buscando igual.
 */
final class MencionResponsabilidad
{
    /** Palabras que no son de un nombre: papeles, títulos y conectores. */
    private const NO_NOMBRE = [
        'profesor', 'profesora', 'guia', 'patrocinante', 'informante', 'correferente', 'co', 'referente',
        'srta', 'sra', 'sr', 'dr', 'dra', 'prof', 'mg', 'ing', 'lic', 'phd', 'msc',
        'ingeniero', 'ingeniera', 'licenciado', 'licenciada', 'magister', 'doctor', 'doctora',
        'universidad', 'facultad', 'departamento', 'escuela', 'instituto', 'centro',
        'compiled', 'edited', 'editor', 'editors', 'by', 'and', 'with', 'translated', 'introduction',
        'compilador', 'compiladora', 'editado', 'editora', 'coordinador', 'coordinadora', 'traduccion',
        'por', 'con', 'colaboracion', 'presentacion', 'prologo', 'introduccion', 'otros', 'et', 'al',
    ];

    /** Partículas de apellidos compuestos, que no cuentan para decidir. */
    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'van', 'von', 'da', 'do', 'dos', 'di', 'le'];

    public static function aporta(Libro $libro): bool
    {
        $mencion = trim((string) $libro->mencion_responsabilidad);
        if ($mencion === '') {
            return false;
        }

        $conocidas = self::palabras(collect([
            ...$libro->autores->pluck('nombre'),
            $libro->profesor_guia, $libro->entidad_corporativa, $libro->entidad_secundaria, $libro->reunion,
        ])->filter()->implode(' '));

        // Cada persona de la mención: «A, B y C», «A ; B», «A and B», «profesor guía: B»
        foreach (preg_split('/\s*(?:[,;:\/&]|\s(?:y|e|and)\s)\s*/u', $mencion) as $trozo) {
            $propias = array_diff(self::palabras($trozo), self::PARTICULAS);
            if ($propias === [] || array_intersect($propias, $conocidas) !== []) {
                continue;      // vacío, o ya está en la ficha (basta un apellido o nombre)
            }
            if (array_intersect($propias, self::NO_NOMBRE) === [] && self::pareceNombre($trozo)) {
                return true;   // una persona que la ficha no muestra
            }
        }

        return false;
    }

    /** @return list<string> palabras sin tildes, en minúscula, de 2 letras o más */
    private static function palabras(string $texto): array
    {
        preg_match_all('/\p{L}{2,}/u', mb_strtolower(Texto::sinTildes($texto)), $m);

        return array_values(array_unique($m[0]));
    }

    /** Todas sus palabras empiezan con mayúscula, salvo las partículas («María de la Cruz»). */
    private static function pareceNombre(string $trozo): bool
    {
        preg_match_all('/\p{L}[\p{L}\'’-]*/u', $trozo, $m);
        foreach ($m[0] as $palabra) {
            if (! in_array(mb_strtolower($palabra), self::PARTICULAS, true) && ! preg_match('/^\p{Lu}/u', $palabra)) {
                return false;
            }
        }

        return $m[0] !== [];
    }
}
