<?php

namespace App\Support;

/**
 * Catálogo fijo de permisos del panel de personal. Vive en código (no en la base)
 * porque cada clave corresponde a un chequeo real en routes/api.php
 * (middleware 'permiso:<clave>'): un permiso creado solo en la base no protegería
 * nada. Lo que sí se administra desde la app es qué permisos tiene cada rol
 * (tabla roles, pantalla Personal y roles).
 */
final class Permisos
{
    /**
     * Permisos que ningún rol editable puede recibir, reservados al rol con acceso
     * total (Administrador): quien administra cuentas y roles podría darse a sí mismo
     * cualquier otro permiso, y desactivar a un usuario lo deja sin entradas, préstamos
     * ni portal (habilitarlo, en cambio, lo puede hacer todo el personal).
     */
    public const SOLO_ACCESO_TOTAL = ['personal.gestionar', 'usuarios.desactivar', 'vigencia.gestionar'];

    public const GRUPOS = [
        'atencion' => [
            'nombre' => 'Atención de público',
            'descripcion' => 'El trabajo diario del mesón de circulación.',
            'permisos' => [
                'entradas.registrar' => ['Registrar entradas', 'Marcar la asistencia (RUT, QR, externos y visitas) y consultar el historial de entradas.'],
                'prestamos.gestionar' => ['Préstamos y devoluciones', 'Prestar y recibir libros y equipos, y gestionar las reservas de libros.'],
                'salas.gestionar' => ['Salas de estudio', 'Reservar salas y logias, confirmar la llegada del grupo, liberar y recibir la llave.'],
                'salas.hora_pasada' => ['Reservas en horas ya pasadas', 'Registrar después una reserva de sala que se atendió sin pasar por el sistema.'],
                'reservas.editar' => ['Editar reservas de salas', 'Desde el Listado de reservas: cambiar la sala, el día o el horario de una reserva activa, corregir sus integrantes, cancelarla o registrar una devolución de llave que quedó pendiente.'],
                'usuarios.ver' => ['Consultar usuarios', 'Ver el listado de usuarios y emitir la Constancia de No Multa.'],
                'usuarios.habilitar' => ['Renovar cuentas de usuarios', 'Renovar la cuenta de quien está vencido o inactivo (por ejemplo, porque su registro venció en Horizon) para que pueda marcar su entrada, pedir préstamos y entrar al portal. Se hace desde el listado de Usuarios o al atenderlo en Préstamos y Entradas. La cuenta queda vigente hasta el 31 de marzo del año siguiente.'],
            ],
        ],
        'multas' => [
            'nombre' => 'Multas',
            'descripcion' => 'Deudas de los usuarios con la biblioteca.',
            'permisos' => [
                'multas.ver' => ['Ver multas', 'Consultar las multas de cada usuario y el listado de deudas pendientes.'],
                'multas.cobrar' => ['Registrar pagos', 'Marcar una multa como pagada cuando el usuario paga.'],
                'multas.crear' => ['Cursar multas manuales', 'Registrar una multa por daño, pérdida u otro motivo (las de atraso se generan solas).'],
                'multas.anular' => ['Anular multas', 'Dejar sin efecto una multa indicando el motivo. Queda registrado quién la anuló.'],
            ],
        ],
        'coleccion' => [
            'nombre' => 'Colección',
            'descripcion' => 'Procesos técnicos sobre libros y equipos.',
            'permisos' => [
                'libros.catalogar' => ['Catalogar libros', 'Crear fichas de libros nuevos, agregar copias, editar datos y generar códigos de barra.'],
                'libros.estado' => ['Cambiar estado de un libro', 'Cambiar el estado físico de un ejemplar, incluido darlo de baja.'],
                'libros.cambio_masivo' => ['Cambio masivo de estado', 'Cambiar el estado de muchos ejemplares a la vez (ej. inventario anual).'],
                'equipos.gestionar' => ['Administrar recursos de apoyo', 'Agregar notebooks, audífonos, cargadores, bicicletas y demás recursos de apoyo, y darlos de baja.'],
            ],
        ],
        'consultas' => [
            'nombre' => 'Consultas e informes',
            'descripcion' => 'Información de solo lectura.',
            'permisos' => [
                'listados.ver' => ['Listados de préstamos, reservas y libros', 'Consultar el listado general de préstamos, el de reservas de salas y el catálogo de libros.'],
                'historiales.ver' => ['Historiales', 'Ver el historial de cambios de estado y el historial de préstamos de cada libro.'],
                'reportes.ver' => ['Reportes', 'Ver gráficos y estadísticas de préstamos, ingresos y uso de salas.'],
                'registro_absoluto.ver' => ['Registro absoluto', 'Ver el registro unificado de todas las operaciones y la bitácora de quién hizo qué (préstamos, multas, cambios de estado, altas y ediciones).'],
            ],
        ],
        'sistema' => [
            'nombre' => 'Administración del sistema',
            'descripcion' => 'Configuración que afecta a toda la biblioteca.',
            'permisos' => [
                'codigo_qr.ver' => ['Mostrar el código QR de acceso', 'Mostrar en pantalla el QR que los usuarios escanean para marcar su entrada.'],
                'codigo_qr.regenerar' => ['Regenerar el código QR', 'Crear un QR nuevo — el anterior deja de funcionar de inmediato.'],
                'configuracion.editar' => ['Configuración institucional', 'Cambiar el nombre y cargo de quien firma la Constancia de No Multa.'],
                'catalogos.gestionar' => ['Catálogos del sistema', 'Crear estados personalizados de libro, ubicaciones, tipos de material y categorías de recursos de apoyo (ej. bicicletas), y administrar las reglas de préstamo y los días sin atención (feriados, vacaciones y cierres).'],
                'usuarios.gestionar' => ['Crear y editar estudiantes', 'Registrar estudiantes y otros usuarios (con RUT, pasaporte o DNI), editar sus datos y cargarlos en forma masiva desde Excel o CSV.'],
                'usuarios.desactivar' => ['Desactivar usuarios', 'Dejar inactivo a un usuario: no podrá marcar su entrada, pedir préstamos ni entrar al portal hasta que alguien renueve su cuenta.'],
                'vigencia.gestionar' => ['Vigencia de las cuentas', 'Definir cuánto dura la cuenta de estudiantes, docentes y funcionarios al renovarla (fecha fija, años o sin vencimiento) y elegir otra fecha de vencimiento al hacer una carga masiva.'],
                'personal.gestionar' => ['Cuentas y roles del personal', 'Crear cuentas, asignar roles y definir qué puede hacer cada rol.'],
            ],
        ],
    ];

    /** @return list<string> */
    public static function claves(): array
    {
        $claves = [];
        foreach (self::GRUPOS as $grupo) {
            array_push($claves, ...array_keys($grupo['permisos']));
        }

        return $claves;
    }

    /** @return list<string> */
    public static function asignables(): array
    {
        return array_values(array_diff(self::claves(), self::SOLO_ACCESO_TOTAL));
    }

    public static function etiqueta(string $clave): string
    {
        foreach (self::GRUPOS as $grupo) {
            if (isset($grupo['permisos'][$clave])) {
                return $grupo['permisos'][$clave][0];
            }
        }

        return $clave;
    }

    /** Forma que consume la matriz de permisos del frontend. */
    public static function catalogo(): array
    {
        return collect(self::GRUPOS)->map(fn ($grupo, $clave) => [
            'clave' => $clave,
            'nombre' => $grupo['nombre'],
            'descripcion' => $grupo['descripcion'],
            'permisos' => collect($grupo['permisos'])->map(fn ($p, $clavePermiso) => [
                'clave' => $clavePermiso,
                'nombre' => $p[0],
                'descripcion' => $p[1],
                'solo_acceso_total' => in_array($clavePermiso, self::SOLO_ACCESO_TOTAL, true),
            ])->values(),
        ])->values()->all();
    }
}
