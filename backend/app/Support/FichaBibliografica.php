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
            ['clasificacion', '082', 'Número de clasificación Dewey', true],
            ['autor_principal', '100', 'Autor principal', true],
            ['entidad_corporativa', '110', 'Entidad corporativa', true],
            ['reunion', '111', 'Reunión o conferencia', true],
            ['titulo', '245', 'Título', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['edicion', '250', 'Edición', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['materias_personales', '600', 'Materia (nombre personal)', true],
            ['materias', '650', 'Materias', true],
            ['materias_geograficas', '651', 'Materia (nombre geográfico)', true],
            ['autores_secundarios', '700', 'Autores secundarios', true],
            ['entidad_secundaria', '710', 'Entidad corporativa relacionada', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
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
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['nota_tesis', '502', 'Nota de tesis', true],
            ['materias', '650', 'Materias', true],
            ['autores_secundarios', '700', 'Autores secundarios', true],
            ['profesor_guia', '700', 'Profesor guía', true],
            ['entidad_secundaria', '710', 'Entidad corporativa relacionada', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['nota_bibliografia', '504', 'Nota de bibliografía', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
        'juego_educativo' => [
            ['clasificacion', '099', 'Número de clasificación local', true],
            ['titulo', '245', 'Título', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '264', 'Producción y publicación', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['resumen', '520', 'Resumen', true],
            ['audiencia', '521', 'Audiencia', true],
            ['creacion_registro', '008', 'Creación del registro', false],
            ['idioma', '041', 'Idioma', false],
            ['catalogador', '910', 'Catalogador', false],
        ],
        'publicacion_seriada' => [
            ['issn', '022', 'ISSN', true],
            ['clasificacion', '099', 'Número de clasificación local', true],
            ['titulo', '245', 'Título', true],
            ['titulo_alternativo', '246', 'Título alternativo', true],
            ['pie_imprenta', '260', 'Pie de imprenta', true],
            ['descripcion_fisica', '300', 'Descripción física', true],
            ['designacion_volumen', '362', 'Fecha de publicación y volumen', true],
            ['materias', '650', 'Materias', true],
            ['url_acceso', '856', 'Acceso electrónico', true],
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

            $valor = self::valor($libro, $clave);
            if ($valor === null || $valor === '') {
                continue;
            }

            $filas[] = ['etiqueta' => $etiqueta, 'campo' => $nombre, 'valor' => $valor, 'interno' => ! $publico];
        }

        return $filas;
    }

    private static function valor(Libro $libro, string $clave): ?string
    {
        return match ($clave) {
            'autor_principal' => $libro->autores->first()?->nombre,
            'autores_secundarios' => $libro->autores->slice(1)->pluck('nombre')->implode('; '),
            'materias' => $libro->categorias->pluck('nombre')->implode('; '),
            'materias_personales', 'materias_geograficas' => self::lineas($libro->{$clave}),
            'pie_imprenta' => self::pieDeImprenta($libro),
            'catalogador' => $libro->catalogadoPor?->nombre,
            'creacion_registro' => $libro->created_at?->format('d-m-Y'),
            default => $libro->{$clave} === null ? null : trim((string) $libro->{$clave}),
        };
    }

    /** "Lugar : Editorial, año", como se transcribe en la ficha (260/264). */
    private static function pieDeImprenta(Libro $libro): ?string
    {
        $lugarYEditorial = collect([$libro->lugar_publicacion, $libro->editorial])->filter()->implode(' : ');

        return collect([$lugarYEditorial, $libro->anio_publicacion])->filter()->implode(', ') ?: null;
    }

    private static function lineas(?string $texto): ?string
    {
        return collect(preg_split('/\R/', (string) $texto))->map(fn ($l) => trim($l))->filter()->implode('; ') ?: null;
    }
}
