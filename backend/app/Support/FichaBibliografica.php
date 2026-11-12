<?php

namespace App\Support;

use App\Models\Libro;

/**
 * Qué etiquetas MARC lleva la ficha de cada tipo de material y cuáles ve el
 * usuario en el portal, según el documento de etiquetas de la biblioteca
 * (29-09-2026). El tipo de material elige su plantilla en Administración.
 *
 * Cada campo es [clave, etiqueta MARC, nombre, público]. Las claves son columnas
 * de `libros`, salvo las compuestas que arma valor(): autores, pie de imprenta,
 * materias, catalogador y creación del registro.
 */
final class FichaBibliografica
{
    public const PLANTILLAS = [
        'libro' => 'Libro',
        'trabajo_titulacion' => 'Trabajo de titulación',
        'juego_educativo' => 'Juego educativo',
        'publicacion_seriada' => 'Publicación seriada (revista)',
    ];

    private const CAMPOS = [
        'libro' => [
            ['isbn', '020', 'ISBN', true],
            // El de la colección o la obra completa, o el que comparte con otros tomos
            ['otros_isbn', '020', 'Otros ISBN', true],
            ['clasificacion', '082', 'Número de clasificación Dewey', true],
            ['autor_principal', '100', 'Autor principal', true],
            ['entidad_corporativa', '110', 'Entidad corporativa', true],
            ['reunion', '111', 'Reunión o conferencia', true],
            ['titulo', '245', 'Título', true],
            ['mencion_responsabilidad', '245', 'Mención de responsabilidad', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['edicion', '250', 'Edición', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['contenido', '505', 'Nota de contenido', true],
            ['materias_personales', '600', 'Materia (nombre personal)', true],
            ['materias', '650', 'Materias', true],
            ['materias_geograficas', '651', 'Materia (nombre geográfico)', true],
            ['autores_secundarios', '700', 'Autores secundarios', true],
            ['entidad_secundaria', '710', 'Entidad corporativa relacionada', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
            ['otros_enlaces', '856', 'Otros enlaces', true],
            ['nota_publica', '500', 'Nota pública', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['serie', '490', 'Serie', false],
            ['nota_interna', '500', 'Notas generales', false],
            ['nota_bibliografia', '504', 'Nota de bibliografía', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
        'trabajo_titulacion' => [
            ['clasificacion', '099', 'Número de clasificación local', true],
            ['autor_principal', '100', 'Autor principal', true],
            ['titulo', '245', 'Título', true],
            ['mencion_responsabilidad', '245', 'Mención de responsabilidad', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['nota_tesis', '502', 'Nota de tesis', true],
            ['contenido', '505', 'Nota de contenido', true],
            ['materias', '650', 'Materias', true],
            ['autores_secundarios', '700', 'Autores secundarios', true],
            ['profesor_guia', '700', 'Profesor guía', true],
            ['entidad_secundaria', '710', 'Entidad corporativa relacionada', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
            ['otros_enlaces', '856', 'Otros enlaces', true],
            ['nota_publica', '500', 'Nota pública', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['nota_bibliografia', '504', 'Nota de bibliografía', false],
            ['nota_interna', '500', 'Notas generales', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
        'juego_educativo' => [
            ['clasificacion', '099', 'Número de clasificación local', true],
            ['titulo', '245', 'Título', true],
            ['mencion_responsabilidad', '245', 'Mención de responsabilidad', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '264', 'Producción y publicación', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['resumen', '520', 'Resumen', true],
            ['audiencia', '521', 'Audiencia', true],
            ['nota_publica', '500', 'Nota pública', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['nota_interna', '500', 'Notas generales', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
        'publicacion_seriada' => [
            ['issn', '022', 'ISSN', true],
            ['clasificacion', '099', 'Número de clasificación local', true],
            ['titulo', '245', 'Título', true],
            ['mencion_responsabilidad', '245', 'Mención de responsabilidad', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['designacion_volumen', '362', 'Fecha de publicación y volumen', true],
            ['materias', '650', 'Materias', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
            ['otros_enlaces', '856', 'Otros enlaces', true],
            ['nota_publica', '500', 'Nota pública', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['frecuencia', '310', 'Frecuencia de publicación', false],
            ['nota_interna', '500', 'Notas generales', false],
            ['nota_idioma', '546', 'Nota de idioma', false],
            ['nota_local', '590', 'Nota local (donación, canje o adquisición)', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
    ];

    /** Plantillas con sus campos, para armar el formulario de catalogación. */
    public static function plantillas(): array
    {
        return collect(self::PLANTILLAS)->map(fn (string $nombre, string $clave) => [
            'clave' => $clave,
            'nombre' => $nombre,
            'campos' => array_map(fn (array $c) => [
                'clave' => $c[0],
                'etiqueta' => $c[1],
                'nombre' => $c[2],
                'publico' => $c[3],
            ], self::CAMPOS[$clave]),
        ])->values()->all();
    }

    public static function plantillaDe(Libro $libro): string
    {
        return $libro->tipoMaterial?->plantilla ?? 'libro';
    }

    /**
     * Filas con valor de la ficha del libro, en el orden de la plantilla. El portal
     * pide solo las públicas; el personal, todas (las internas van marcadas).
     *
     * @return array<int, array{etiqueta: string, campo: string, valor: string, interno: bool}>
     */
    public static function ficha(Libro $libro, bool $conInternos = false): array
    {
        $filas = [];
        foreach (self::CAMPOS[self::plantillaDe($libro)] as [$clave, $etiqueta, $nombre, $publico]) {
            if (! $publico && ! $conInternos) {
                continue;
            }

            // Los secundarios (700), una fila por papel: «Autores secundarios», «Compiladores»,
            // «Traductores»… así se distingue quién es qué sin leer abreviaturas.
            if ($clave === 'autores_secundarios') {
                foreach (self::secundariosPorPapel($libro) as $campo => $autores) {
                    $filas[] = [
                        'etiqueta' => $etiqueta, 'campo' => $campo, 'valor' => $autores->pluck('nombre')->implode('; '), 'interno' => ! $publico,
                        'items' => self::itemsAutores($autores),
                    ];
                }

                continue;
            }

            $valor = self::valor($libro, $clave);
            if ($valor === null || $valor === '') {
                continue;
            }

            $filas[] = ['etiqueta' => $etiqueta, 'campo' => $nombre, 'valor' => $valor, 'interno' => ! $publico, 'items' => self::items($libro, $clave, $valor)];
        }

        // Las carreras a las que sirve (Catalogación → Carreras asociadas), en cualquier
        // plantilla: una fila más, con la etiqueta local 690, en su lugar tras las materias.
        if ($libro->carreras->isNotEmpty()) {
            $carreras = ['etiqueta' => '690', 'campo' => 'Carreras asociadas', 'valor' => $libro->carreras->pluck('nombre')->sort()->implode('; '), 'interno' => false,
                'items' => $libro->carreras->sortBy('nombre')->map(fn ($c) => ['texto' => $c->nombre, 'carrera_id' => $c->id])->values()->all()];
            $despues = collect($filas)->search(fn ($fila) => $fila['etiqueta'] > '690');
            array_splice($filas, $despues === false ? count($filas) : $despues, 0, [$carreras]);
        }

        return $filas;
    }

    private static function valor(Libro $libro, string $clave): ?string
    {
        return match ($clave) {
            // 100 solo si el primero es autor: una obra compilada o editada no tiene autor
            // principal (se registra por el título) y sus compiladores o editores van en 700.
            'autor_principal' => self::principal($libro)?->nombre,
            'autores_secundarios' => $libro->autores->reject(fn ($a) => $a === self::principal($libro))->map(fn ($a) => self::conRol($a))->implode('; '),
            // Como en el registro (490): entre paréntesis, «(Serie Harry Potter ; 1)».
            // Cada una entre paréntesis, «(Notas de matemática ; 85) (North-Holland mathematics studies ; 67)»
            'serie' => filled($libro->serie) ? collect(preg_split('/\R/', $libro->serie))->map(fn ($s) => trim($s))->filter()->map(fn ($s) => "({$s})")->implode(' ') : null,
            // 245 $c solo si nombra a alguien que la ficha no muestra (casi siempre repite el 100/700)
            'mencion_responsabilidad' => MencionResponsabilidad::aporta($libro) ? trim($libro->mencion_responsabilidad) : null,
            'materias' => $libro->categorias->pluck('nombre')->implode('; '),
            'titulo_alternativo', 'materias_personales', 'materias_geograficas' => self::lineas($libro->{$clave}),
            'pie_imprenta' => self::pieDeImprenta($libro),
            'catalogador' => $libro->catalogadoPor?->nombre,
            'creacion_registro' => $libro->created_at?->format('d-m-Y'),
            default => $libro->{$clave} === null ? null : trim((string) $libro->{$clave}),
        };
    }

    /** @return array<string, string> «Compiladores» => «Moulder, David S.; Varley, Allen», en el orden de la ficha. */
    private static function secundariosPorPapel(Libro $libro): array
    {
        $principal = self::principal($libro);

        return $libro->autores
            ->reject(fn ($a) => $a === $principal)
            ->groupBy(fn ($a) => RolesAutor::plural($a->pivot->rol ?? null))
            ->all();
    }

    /** Cada autor por separado, con su id para filtrar el listado por él. */
    private static function itemsAutores($autores): array
    {
        return $autores->map(fn ($a) => ['texto' => $a->nombre, 'autor_id' => $a->id])->values()->all();
    }

    /**
     * Los valores de una fila por separado, para mostrarlos como etiquetas y no unidos con
     * «;»: autores y materias con su id (se filtra por ellos), el profesor guía con su nombre
     * para buscarlo; el resto, solo partido donde había «; ». Null si es un solo valor común.
     */
    private static function items(Libro $libro, string $clave, string $valor): ?array
    {
        return match ($clave) {
            'autor_principal' => self::itemsAutores(collect([self::principal($libro)])),
            'materias' => $libro->categorias->map(fn ($c) => ['texto' => $c->nombre, 'categoria_id' => $c->id])->values()->all(),
            // Cada enlace con su texto, o «Abrir enlace»
            'otros_enlaces' => collect(preg_split('/\R/', (string) $libro->otros_enlaces))->map(fn ($l) => trim($l))->filter()
                ->map(fn ($l) => ['texto' => trim(substr($l, strlen(strtok($l, ' ')))) ?: 'Abrir enlace', 'url' => strtok($l, ' ')])->values()->all(),
            'profesor_guia' => collect(explode(';', $valor))->map(fn ($n) => trim($n))->filter()->map(fn ($n) => ['texto' => $n, 'buscar' => $n])->values()->all(),
            // Solo las listas se separan en chips. En el resto, « ; » es puntuación ISBD y va
            // entero: «(Aporte al debate laboral ; 34)», «86 p. : il. ; 24 cm.».
            default => in_array($clave, self::LISTAS, true) && str_contains($valor, '; ')
                ? collect(explode('; ', $valor))->filter()->map(fn ($t) => ['texto' => $t])->values()->all() : null,
        };
    }

    /** Campos que son listas, unidas con «; » (valor() y la migración desde Horizon). */
    private const LISTAS = ['titulo_alternativo', 'materias_personales', 'materias_geograficas', 'entidad_secundaria', 'nota_idioma'];

    /** El autor principal (100): el primero, si su papel es autor (sin rol). */
    private static function principal(Libro $libro)
    {
        $primero = $libro->autores->first();

        return $primero && blank($primero->pivot->rol ?? null) ? $primero : null;
    }

    /** «Pérez, Juan, trad.»: el nombre con la abreviatura de su papel (700 $e), si no es autor. */
    private static function conRol($autor): string
    {
        $abreviatura = RolesAutor::abreviatura($autor->pivot->rol ?? null);

        return $abreviatura ? "{$autor->nombre}, {$abreviatura}" : $autor->nombre;
    }

    /** "Lugar : Editorial, año", como se transcribe en la ficha (260/264). */
    private static function pieDeImprenta(Libro $libro): ?string
    {
        $lugarYEditorial = collect([$libro->lugar_publicacion, $libro->editorial])->filter()->implode(' : ');

        $pie = collect([$lugarYEditorial, self::fechaDelPie($libro)])->filter()->implode(', ');

        // Como en el registro original (Horizon: «Boston : WCB/McGraw-Hill, 1997.»), el
        // 260/264 cierra siempre con punto (salvo que ya termine en uno).
        return $pie === '' ? null : (str_ends_with($pie, '.') ? $pie : "{$pie}.");
    }

    /**
     * La fecha como la escribió Horizon («c2001», «[19--]», «1990, c1963») mientras siga
     * calzando con el año del libro; si el año se corrigió después al catalogar, el año.
     */
    private static function fechaDelPie(Libro $libro): string|int|null
    {
        $fecha = trim((string) $libro->fecha_publicacion);
        $anio = $libro->anio_publicacion;

        return $fecha !== '' && ($anio === null || str_contains($fecha, (string) $anio)) ? $fecha : $anio;
    }

    private static function lineas(?string $texto): ?string
    {
        return collect(preg_split('/\R/', (string) $texto))->map(fn ($l) => trim($l))->filter()->implode('; ') ?: null;
    }
}
