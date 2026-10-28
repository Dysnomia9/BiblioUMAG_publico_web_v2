export type ModuloStaff = {
  name: string
  label: string
  /** Basta con tener cualquiera de estos permisos para abrir el módulo. */
  permiso: string | string[]
}

/**
 * Permiso que exige cada módulo del panel de personal — el mismo que exigen sus
 * endpoints en el backend (routes/api.php). Lo usan la guarda del router, la barra
 * superior, el Menú Gestiones Admin y los atajos de teclado. Las rutas que no
 * aparecen acá (Inicio) las puede abrir todo el personal.
 */
const MODULOS: ModuloStaff[] = [
  { name: 'entrada', label: 'Entradas', permiso: 'entradas.registrar' },
  { name: 'prestamo', label: 'Préstamos', permiso: 'prestamos.gestionar' },
  { name: 'salas', label: 'Salas', permiso: 'salas.gestionar' },
  { name: 'reportes', label: 'Reportes', permiso: 'reportes.ver' },
  { name: 'usuarios', label: 'Usuarios', permiso: 'usuarios.ver' },
  { name: 'multas', label: 'Multas', permiso: 'multas.ver' },
  { name: 'listado-libros', label: 'Listado de material', permiso: 'listados.ver' },
  { name: 'catalogacion-libros', label: 'Catalogación', permiso: 'libros.catalogar' },
  { name: 'estado-libro', label: 'Estado de ejemplares', permiso: 'libros.estado' },
  { name: 'cambio-masivo-estado', label: 'Cambio Masivo de Estado', permiso: 'libros.cambio_masivo' },
  { name: 'etiquetas', label: 'Etiquetas de Código', permiso: 'libros.catalogar' },
  { name: 'equipos', label: 'Recursos de apoyo', permiso: 'equipos.gestionar' },
  { name: 'listado-prestamos', label: 'Listado Préstamos', permiso: 'listados.ver' },
  { name: 'listado-reservas', label: 'Listado Reservas', permiso: 'listados.ver' },
  { name: 'historial-estado-libro', label: 'Historial de Estado', permiso: 'historiales.ver' },
  { name: 'historial-prestamos-libro', label: 'Historial de material', permiso: 'historiales.ver' },
  { name: 'registro-absoluto', label: 'Registro Absoluto', permiso: 'registro_absoluto.ver' },
  { name: 'codigo-qr', label: 'Código QR', permiso: ['codigo_qr.ver', 'codigo_qr.regenerar'] },
  { name: 'administracion', label: 'Administración', permiso: ['configuracion.editar', 'catalogos.gestionar', 'usuarios.gestionar', 'libros.estado'] },
  { name: 'personal', label: 'Personal y Roles', permiso: 'personal.gestionar' },
]

export function itemPorRuta(nombre: unknown): ModuloStaff | undefined {
  return MODULOS.find((m) => m.name === nombre)
}
