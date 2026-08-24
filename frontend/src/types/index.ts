export type Staff = {
  id: number
  email: string
  nombre: string
  rol: 'admin' | 'staff'
}

export type Usuario = {
  id: number
  rut: string
  nombre: string
  apellido: string
  email: string | null
  tipo: 'estudiante' | 'docente' | 'funcionario'
  carrera: string | null
  anio_ingreso: number | null
  sexo: string | null
  activo: boolean
  qr_code: string | null
  multas_pendientes?: { cantidad: number; monto_total: number }
}

export type Equipo = {
  id: number
  codigo_inventario: string
  codigo_barras: string
  tipo: 'audifonos' | 'notebook' | 'cargador'
  disponible: boolean
  activo: boolean
}

export type Entrada = {
  id: number
  usuario_id: number | null
  rut_externo: string | null
  nombre_externo: string | null
  fecha_hora_entrada: string
  fecha_hora_salida: string | null
  via: 'manual' | 'qr'
  codigo_barras: string | null
  es_convenio: boolean
  es_visita: boolean
  usuario?: Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'rut' | 'tipo'>
}

export type Prestamo = {
  id: number
  usuario_id: number
  ejemplar_id: number | null
  equipo_id: number | null
  libro_titulo: string
  tipo_item: 'libro' | 'audifonos' | 'notebook' | 'cargador'
  fecha_prestamo: string
  fecha_devolucion: string | null
  fecha_devolucion_real: string | null
  estado: 'activo' | 'atrasado' | 'devuelto'
  prestado_por: string | null
  prestado_por_staff_id?: number | null
  devuelto_por: string | null
  devuelto_por_staff_id?: number | null
  multa_monto: number | null
  multa_estado: 'pendiente' | 'pagada' | null
  multa_pagada_en: string | null
  multa_pagada_por: string | null
  multa_pagada_por_staff_id?: number | null
  usuario?: Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'rut'>
}

export type ResumenDashboard = {
  usuariosActivos: number
  entradasHoy: number
  personasEnSala: number
  prestamosActivos: number
  prestamosAtrasados: number
  ultimasEntradas: Entrada[]
  ultimosPrestamos: Prestamo[]
}

export type EstadoProcesoEjemplar =
  | 'inventario'
  | 'procesos_tecnicos'
  | 'por_colocar'
  | 'en_estante'
  | 'estanteria_auxiliar'
  | 'de_baja'
  | 'coleccion_movil'
  | 'personalizado'

export type Autor = { id: number; nombre: string }
export type Categoria = { id: number; nombre: string }
export type Carrera = { id: number; nombre: string }
export type Ubicacion = { id: number; nombre: string }
export type TipoMaterial = { id: number; nombre: string }

export type EstadoLibroPersonalizado = {
  id: number
  nombre: string
  descripcion: string | null
  activo: boolean
}

/** Copia física de un `Libro` — cada una con su propio código de barras y estado. */
export type Ejemplar = {
  id: number
  libro_id: number
  numero_copia: number
  codigo_barras: string
  disponible: boolean
  estado_proceso: EstadoProcesoEjemplar
  estado_personalizado_id: number | null
  estado_personalizado?: EstadoLibroPersonalizado | null
  ubicacion_id: number | null
  ubicacion?: Ubicacion | null
  volumen: string | null
  precio: string | null
  fecha_inventario: string | null
  libro?: Libro
}

/** Registro bibliográfico (la "obra") — puede tener una o varias copias físicas (`Ejemplar`). */
export type Libro = {
  id: number
  titulo: string
  isbn: string | null
  clasificacion: string | null
  coleccion: string | null
  editorial: string | null
  anio_publicacion: number | null
  tipo_material_id: number | null
  tipo_material?: TipoMaterial | null
  nota_interna: string | null
  nota_publica: string | null
  autores?: Autor[]
  categorias?: Categoria[]
  carreras?: Carrera[]
  ejemplares?: Ejemplar[]
  ejemplares_count?: number
  ejemplares_total?: number
  ejemplares_disponibles?: number
}

export type EjemplarEstadoHistorial = {
  id: number
  ejemplar_id: number
  estado_anterior: EstadoProcesoEjemplar
  estado_nuevo: EstadoProcesoEjemplar
  estado_personalizado_anterior_id: number | null
  estado_personalizado_nuevo_id: number | null
  staff_id: number | null
  lote_id: string | null
  motivo: string | null
  created_at: string
  ejemplar?: Ejemplar & { libro?: Pick<Libro, 'id' | 'titulo'> }
  staff?: { id: number; nombre: string }
  estado_personalizado_anterior?: Pick<EstadoLibroPersonalizado, 'id' | 'nombre'> | null
  estado_personalizado_nuevo?: Pick<EstadoLibroPersonalizado, 'id' | 'nombre'> | null
}

export type CambioMasivoFiltro = {
  ubicacion_id?: number
  estado_proceso_actual?: string
  tipo_material_id?: number
  categoria_id?: number
  q?: string
}

export type CambioMasivoPreview = {
  total: number
  muestra: (Pick<Ejemplar, 'id' | 'libro_id' | 'codigo_barras' | 'numero_copia' | 'estado_proceso' | 'ubicacion_id' | 'ubicacion'> & {
    libro?: Pick<Libro, 'id' | 'titulo'>
  })[]
}

export type LibroHistorialEjemplar = {
  id: number
  numero_copia: number
  codigo_barras: string
  total_prestamos: number
  prestamos: {
    id: number
    usuario: string | null
    fecha_prestamo: string
    fecha_devolucion_real: string | null
    estado: string
  }[]
}

export type LibroHistorial = {
  libro: Pick<Libro, 'id' | 'titulo' | 'isbn'>
  ejemplares: LibroHistorialEjemplar[]
  total_prestamos: number
}

export type ConfiguracionInstitucional = {
  id: number
  jefe_unidad_nombre: string
  jefe_unidad_cargo: string
}

export type EstadoPortal = {
  usuario: Usuario
  personasEnSala: number
  capacidad: number
}

export type ReservaLibro = {
  id: number
  usuario_id: number
  libro_id: number
  ejemplar_id: number | null
  fecha_reserva: string
  fecha_retiro: string | null
  estado: 'pendiente' | 'retirado' | 'cancelado' | 'en_cola'
  // Ambos solo presentes cuando estado === 'en_cola'.
  posicion?: number | null
  // Estimación (no una promesa exacta): la fecha de devolución más próxima entre los
  // préstamos activos de copias de este libro. Puede venir null si por algún motivo
  // no hay ningún préstamo activo con fecha registrada.
  proxima_fecha_devolucion?: string | null
  libro?: Libro
  ejemplar?: Ejemplar
}

// Tramo reservado dentro de una sala — horario continuo (hora_inicio/hora_fin en
// formato "H:i"), ya no bloques fijos de 2 horas. Forma parte de Sala.tramos, la
// respuesta de SalaController::index()/PortalController::salas() (ver
// ReservaSalaService::vistaDelDia()).
export type TramoReserva = {
  reserva_id: number
  hora_inicio: string
  hora_fin: string
  estado: string
  cantidad_personas: number
  rut_usuario: string
  usuario_id: number | null
  personas: { rut: string; nombre: string | null }[]
  prestado_por: string | null
  devuelto_por: string | null
  hora_prestamo_real: string | null
  hora_devolucion_real: string | null
  plazo_confirmacion: string
  vencida_sin_confirmar: boolean
}

export type Sala = {
  id: number
  nombre: string
  capacidad: number
  tipo: 'logia' | 'puesto' | 'sala'
  codigo_barras: string | null
  // Solo si la fecha consultada es hoy — null para otras fechas.
  libre_ahora: boolean | null
  disponible_hasta_min: number | null
  tramos: TramoReserva[]
}

export type VistaSalasDia = {
  fecha: string
  apertura: string
  cierre: string
  granularidad: number
  duracion_minima: number
  duracion_maxima: number
  cuota_diaria: number
  salas: Sala[]
}

export type SerieItem = {
  label: string
  value: number
}

export type HoraPorSala = {
  sala: string
  horas: SerieItem[]
}

export type ReporteResumen = {
  total: number
  promedioPorPeriodo: number
  categoriaMasFrecuente: string
  serie: SerieItem[]
  porCarrera: SerieItem[]
  porSexo: SerieItem[]
  porAnioIngreso: SerieItem[]
  porTipoUsuario: SerieItem[]
  porHora: SerieItem[]
  // Solo con contenido cuando tab === 'logias' / tab === 'libros' respectivamente.
  porSala: SerieItem[]
  porLibro: SerieItem[]
  porHoraPorSala: HoraPorSala[]
}

export type ReporteOpciones = {
  carreras: string[]
  aniosIngreso: number[]
  sexos: string[]
  tiposUsuario: string[]
}

export type Periodo = 'dia' | 'semana' | 'mes' | 'semestre' | 'anio'
export type ReporteTab = 'prestamos' | 'ingresos' | 'logias' | 'libros'

export type MultaPendienteUsuario = {
  usuario_id: number
  nombre: string
  apellido: string
  rut: string
  cantidad_prestamos: number
  monto_total: number
}

export type MultasPendientesResumen = {
  total_usuarios: number
  monto_total: number
  usuarios: MultaPendienteUsuario[]
}

export type CodigoAcceso = {
  id: number
  codigo: string
  generado_por: number | null
  created_at: string
  updated_at: string
}

export type TipoOperacionRegistro = 'prestamo_libro' | 'prestamo_equipo' | 'reserva_sala' | 'reserva_libro' | 'entrada'

export type CategoriaOperacionRegistro = 'prestamo' | 'reserva_sala' | 'reserva_libro' | 'entrada'

export type OperacionRegistro = {
  tipo: TipoOperacionRegistro
  fecha_hora: string | null
  usuario_nombre: string | null
  usuario_rut: string | null
  detalle: string | null
  estado: string | null
  atendido_por: string | null
  origen_id: number
}

export type RegistroAbsolutoResumen = {
  desde: string
  hasta: string
  total: number
  operaciones: OperacionRegistro[]
}
