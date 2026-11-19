<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/** Lista cerrada, en orden de dependencias. Nunca se importan tablas arbitrarias. */
final class TraspasoEsquema
{
    public const CLAVES = [
        'roles' => ['clave'],
        'roles_autor' => ['clave'],
        'categorias_recurso' => ['clave'],
        'tipos_material' => ['nombre'],
        'ubicaciones' => ['nombre'],
        'estados_libro_personalizados' => ['nombre'],
        'carreras' => ['nombre'],
        'categorias' => ['nombre'],
        'autores' => ['nombre'],
        'configuracion_institucional' => ['id'],
        'politicas_vigencia' => ['tipo'],
        'staff' => ['email'],
        'usuarios' => ['rut'],
        'usuario_correos' => ['email'],
        'pestanas_salas' => ['nombre'],
        'salas' => ['codigo'],
        'reglas_prestamo' => ['tipo_usuario', 'tipo_material_id', 'ubicacion_id'],
        'dias_sin_atencion' => ['fecha', 'ubicacion_id'],
        'periodos_plazo_sala' => ['desde', 'hasta'],
        'libros' => ['numero_ficha'],
        'ejemplares' => ['codigo_barras'],
        'equipos' => ['codigo_barras'],
        'fasciculos' => [],
        'fichas_interbibliotecarias' => [],
        'prestamos_interbibliotecarios' => ['ejemplar_id'],
        'entradas' => [],
        'prestamos' => [],
        'reservas' => [],
        'reservas_libro' => [],
        'multas' => [],
        'usos_sin_rut' => [],
        'ejemplar_estado_historial' => [],
        'libro_autor' => ['libro_id', 'autor_id'],
        'libro_categoria' => ['libro_id', 'categoria_id'],
        'libro_carrera' => ['libro_id', 'carrera_id'],
        'reserva_participantes' => ['reserva_id', 'usuario_id'],
        'bitacora' => [],
    ];

    public const COMPLETAS = [
        'roles', 'roles_autor', 'categorias_recurso', 'tipos_material',
        'ubicaciones', 'estados_libro_personalizados', 'carreras', 'categorias',
        'configuracion_institucional', 'politicas_vigencia', 'staff', 'usuarios',
        'pestanas_salas', 'salas', 'reglas_prestamo', 'dias_sin_atencion', 'periodos_plazo_sala',
    ];

    public const MOVIMIENTOS = [
        'entradas', 'prestamos', 'reservas', 'reservas_libro', 'multas',
        'usos_sin_rut', 'ejemplar_estado_historial', 'bitacora', 'prestamos_interbibliotecarios',
    ];

    // Tabla de correspondencias del ETL, columna local y clave original de Horizon.
    public const HORIZON = [
        'usuarios' => ['usuario', 'usuario_id', 'borrower_num'],
        'libros' => ['libro', 'libro_id', 'bib_num'],
        'ejemplares' => ['ejemplar', 'ejemplar_id', 'item_num'],
        'equipos' => ['equipo', 'equipo_id', 'item_num'],
        'prestamos' => ['prestamo', 'prestamo_id', 'clave'],
        'reservas_libro' => ['reserva', 'reserva_id', 'request_num'],
    ];

    public const HIJAS = [
        ['usuarios', 'usuario_correos', 'usuario_id'],
        ['libros', 'libro_autor', 'libro_id'],
        ['libros', 'libro_categoria', 'libro_id'],
        ['libros', 'libro_carrera', 'libro_id'],
        ['libros', 'ejemplares', 'libro_id'],
        ['libros', 'fasciculos', 'libro_id'],
        ['libros', 'fichas_interbibliotecarias', 'libro_id'],
        ['ejemplares', 'prestamos_interbibliotecarios', 'ejemplar_id'],
        ['reservas', 'reserva_participantes', 'reserva_id'],
    ];

    public const ENTIDADES = [
        'Staff' => 'staff', 'Usuario' => 'usuarios', 'Rol' => 'roles',
        'Libro' => 'libros', 'Ejemplar' => 'ejemplares', 'Equipo' => 'equipos',
        'Prestamo' => 'prestamos', 'Entrada' => 'entradas', 'Reserva' => 'reservas', 'Sala' => 'salas',
        'ReservaLibro' => 'reservas_libro', 'Multa' => 'multas',
        'Fasciculo' => 'fasciculos', 'FichaInterbibliotecaria' => 'fichas_interbibliotecarias',
        'PrestamoInterbibliotecario' => 'prestamos_interbibliotecarios',
        'UsoSinRut' => 'usos_sin_rut', 'Autor' => 'autores', 'Categoria' => 'categorias',
        'Carrera' => 'carreras', 'TipoMaterial' => 'tipos_material',
        'Ubicacion' => 'ubicaciones', 'EstadoLibroPersonalizado' => 'estados_libro_personalizados',
        'CategoriaRecurso' => 'categorias_recurso', 'RolAutor' => 'roles_autor',
        'ReglaPrestamo' => 'reglas_prestamo', 'DiaSinAtencion' => 'dias_sin_atencion',
        'PoliticaVigencia' => 'politicas_vigencia', 'PeriodoPlazoSala' => 'periodos_plazo_sala', 'PestanaSala' => 'pestanas_salas',
        'ConfiguracionInstitucional' => 'configuracion_institucional',
        'EjemplarEstadoHistorial' => 'ejemplar_estado_historial', 'UsuarioCorreo' => 'usuario_correos',
    ];

    private array $columnas = [];

    private array $foraneas = [];

    public function columnas(string $tabla): array
    {
        return $this->columnas[$tabla] ??= collect(Schema::getColumns($tabla))
            ->filter(fn ($c) => $c['generation'] === null)->keyBy('name')->all();
    }

    public function foraneas(string $tabla): array
    {
        return $this->foraneas[$tabla] ??= array_values(array_filter(
            Schema::getForeignKeys($tabla),
            fn ($fk) => isset($this->columnas($tabla)[$fk['columns'][0]]),
        ));
    }

    public function firma(): string
    {
        $esquema = [];
        foreach (array_keys(self::CLAVES) as $tabla) {
            foreach ($this->columnas($tabla) as $nombre => $columna) {
                $esquema[$tabla][$nombre] = [$columna['type'], $columna['nullable']];
            }
            $esquema[$tabla]['foraneas'] = $this->foraneas($tabla);
        }

        return hash('sha256', json_encode($esquema, JSON_THROW_ON_ERROR));
    }
}
