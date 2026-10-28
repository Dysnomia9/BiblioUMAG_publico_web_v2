export type Staff = {
  id: number
  email: string
  nombre: string
  rol: string
  rol_nombre: string | null
  rol_acceso_total: boolean
  permisos: string[]
  activo: boolean
}

export type PermisoCatalogo = {
  clave: string
  nombre: string
  descripcion: string
  solo_acceso_total: boolean
}

export type GrupoPermisos = {
  clave: string
  nombre: string
  descripcion: string
  permisos: PermisoCatalogo[]
}

export type Rol = {
  id: number
  clave: string
  nombre: string
  descripcion: string | null
  nivel: number
  acceso_total: boolean
  permisos: string[]
  staff_count: number
}

export type CuentaPersonal = {
  id: number
  nombre: string
  email: string
  rol: string
  rol_nombre: string | null
  activo: boolean
  ultimo_acceso: string | null
  created_at: string
}

export type Usuario = {
  id: number
  /** RUT con puntos y guion o, si es extranjero, el número de pasaporte/DNI (ver tipo_documento). */
  rut: string
  tipo_documento?: 'rut' | 'pasaporte' | 'dni' | 'otro'
  pais?: string | null
  nombre: string
  apellido: string
  email: string | null
  tipo: 'estudiante' | 'docente' | 'funcionario'
  carrera: string | null
  anio_ingreso: number | null
  sexo: string | null
  /** Docentes y funcionarios: unidad o departamento y cargo (no tienen carrera). */
  unidad?: string | null
  cargo?: string | null
  /** Correos adicionales (el principal es `email`). */
  correos?: { id: number; email: string }[]
  /** Sirve hoy: no fue desactivada y su vigencia no pasó. */
  activo: boolean
  /** Último día en que la cuenta sirve (null: sin vencimiento). */
  vigente_hasta?: string | null
  /** Por qué no sirve: pasó su vigencia («vencida») o la desactivó el personal; null si sirve. */
  inactividad?: 'vencida' | 'desactivada' | null
  qr_code: string | null
  multas_pendientes?: { cantidad: number; monto_total: number }
}

/** Un recurso de apoyo físico (notebook, bicicleta…): `tipo` es la clave de su categoría. */
export type Equipo = {
  id: number
  codigo_inventario: string
  codigo_barras: string
  tipo: string
  disponible: boolean
  activo: boolean
  /** Por qué se dio de baja y desde cuándo; null mientras está activo. */
  motivo_baja: string | null
  dado_de_baja_el: string | null
}

/** Categoría de recurso de apoyo (Administración → Catálogos): audífonos, notebook, bicicleta… */
export type CategoriaRecurso = {
  id: number
  clave: string
  nombre: string
  nombre_plural: string
  icono: string
  color: string
  limite_por_persona: number | null
  indicaciones: string | null
  activo: boolean
  recursos_total?: number
  recursos_activos?: number
  recursos_disponibles?: number
}

export type Entrada = {
  id: number
  usuario_id: number | null
  rut_externo: string | null
  tipo_documento_externo?: 'rut' | 'pasaporte' | 'dni' | 'otro' | null
  pais_externo?: string | null
  nombre_externo: string | null
  fecha_hora_entrada: string
  fecha_hora_salida: string | null
  /**
   * manual: la registró el personal; portal: la persona, con su RUT en el portal; qr: escaneó
   * el código; sala: quedó presente al confirmar la llegada a una sala reservada.
   */
  via: 'manual' | 'portal' | 'qr' | 'sala'
  codigo_barras: string | null
  es_convenio: boolean
  es_visita: boolean
  usuario?: Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'rut' | 'tipo' | 'tipo_documento' | 'pais'>
}

/** GET /entrada: las entradas de un día (o de una búsqueda) y el recuadro de hoy. */
export type RespuestaEntradas = {
  modo: 'dia' | 'busqueda'
  fecha?: string
  entradas: Entrada[]
  hoy: { personas: number; entradas: number; qr: number; portal: number; manuales: number; sala: number }
  aforo: number
  horario: HorarioBiblioteca
}

export type Prestamo = {
  id: number
  usuario_id: number
  ejemplar_id: number | null
  equipo_id: number | null
  libro_titulo: string
  /** 'libro' o la clave de una categoría de recurso de apoyo. */
  tipo_item: string
  codigo_barras: string | null
  fecha_prestamo: string
  fecha_devolucion: string | null
  fecha_devolucion_real: string | null
  /** Si se devolvió. El atraso se calcula con fecha_devolucion (estaVencido() en utils/prestamos.ts). */
  estado: 'activo' | 'devuelto'
  prestado_por: string | null
  prestado_por_staff_id?: number | null
  devuelto_por: string | null
  devuelto_por_staff_id?: number | null
  usuario?: Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'rut'>
  multas?: Pick<Multa, 'id' | 'prestamo_id' | 'tipo' | 'monto' | 'estado'>[]
}

export type TipoMulta = 'atraso' | 'deterioro' | 'perdida' | 'otro'
export type EstadoMulta = 'pendiente' | 'pagada' | 'anulada'

export type Multa = {
  id: number
  usuario_id: number
  prestamo_id: number | null
  tipo: TipoMulta
  monto: number
  estado: EstadoMulta
  detalle: string | null
  dias_atraso: number | null
  origen: 'sistema' | 'manual' | 'importada'
  referencia_externa: string | null
  pagada_en: string | null
  anulada_en: string | null
  motivo_anulacion: string | null
  created_at: string
  usuario?: Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'rut' | 'email'>
  prestamo?: Pick<Prestamo, 'id' | 'libro_titulo' | 'tipo_item' | 'fecha_prestamo' | 'fecha_devolucion' | 'fecha_devolucion_real'> | null
  registrada_por?: { id: number; nombre: string } | null
  pagada_por?: { id: number; nombre: string } | null
  anulada_por?: { id: number; nombre: string } | null
}

export type ResumenDashboard = {
  usuariosActivos: number
  entradasHoy: number
  personasEnSala: number
  aforo: number
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
export type PlantillaFichaClave = 'libro' | 'trabajo_titulacion' | 'juego_educativo' | 'publicacion_seriada'

export type TipoMaterial = { id: number; nombre: string; plantilla: PlantillaFichaClave }

/** Una fila de la ficha bibliográfica (etiqueta MARC, nombre del campo y su valor). */
export type FilaFicha = { etiqueta: string; campo: string; valor: string; interno: boolean }

/** Título tal como lo entrega el catálogo del portal: solo datos públicos. */
export type LibroCatalogo = {
  id: number
  titulo: string
  tipo_material: string | null
  autores: { id: number; nombre: string }[]
  categorias: { id: number; nombre: string }[]
  ejemplares_total: number
  ejemplares_disponibles: number
  // Devolución más próxima entre las copias prestadas (null si ninguna lo está).
  proxima_devolucion: string | null
  ficha: FilaFicha[]
  /** Copias en estante: colección (la de la copia o, si no tiene, la del libro) y nota pública. */
  copias?: { numero_copia: number; volumen: string | null; disponible: boolean; ubicacion: string | null; coleccion: string | null; nota_publica: string | null }[]
}

/** Qué etiquetas lleva la ficha de un tipo de material y cuáles ve el usuario. */
export type PlantillaFicha = {
  clave: PlantillaFichaClave
  nombre: string
  campos: { clave: string; etiqueta: string; nombre: string; publico: boolean }[]
}

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
  /** Segundo código de barras, de 8 dígitos, para la etiqueta de lomo (lo asigna el sistema). */
  codigo_lomo: string
  disponible: boolean
  estado_proceso: EstadoProcesoEjemplar
  estado_personalizado_id: number | null
  estado_personalizado?: EstadoLibroPersonalizado | null
  ubicacion_id: number | null
  ubicacion?: Ubicacion | null
  volumen: string | null
  precio: string | null
  forma_adquisicion: 'compra' | 'donacion' | 'canje' | null
  fecha_inventario: string | null
  /** Se ve en la ficha de la copia y en el portal. */
  nota_publica?: string | null
  /** Solo para el personal. */
  nota_interna?: string | null
  /** Colección de la copia; si no tiene, vale la del libro. */
  coleccion?: string | null
  // En /libros: si tiene un préstamo activo o una reserva lista para retirar.
  prestado?: boolean
  reservado?: boolean
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
  titulo_alternativo?: string | null
  edicion?: string | null
  lugar_publicacion?: string | null
  descripcion_fisica?: string | null
  entidad_corporativa?: string | null
  reunion?: string | null
  entidad_secundaria?: string | null
  materias_personales?: string | null
  materias_geograficas?: string | null
  url_acceso?: string | null
  issn?: string | null
  designacion_volumen?: string | null
  nota_tesis?: string | null
  profesor_guia?: string | null
  resumen?: string | null
  audiencia?: string | null
  idioma?: string | null
  serie?: string | null
  nota_bibliografia?: string | null
  frecuencia?: string | null
  nota_idioma?: string | null
  nota_local?: string | null
  /** Nota de contenido (MARC 505): índice de capítulos o volúmenes. */
  contenido?: string | null
  catalogado_por?: { id: number; nombre: string } | null
  ficha?: FilaFicha[]
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
  // Copias que movió en total el cambio masivo, aunque los filtros muestren menos (0 si fue individual).
  lote_total?: number
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
    fecha_devolucion: string | null
    fecha_devolucion_real: string | null
    estado: string
  }[]
}

export type LibroHistorial = {
  libro: Pick<Libro, 'id' | 'titulo' | 'isbn' | 'clasificacion'>
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
  horario: HorarioBiblioteca
}

export type IngresosPortal = {
  este_mes: number
  entradas: { id: number; fecha: string; hora: string; origen: 'qr' | 'rut' | 'meson' }[]
}

export type ActividadPortal = {
  entrada_hoy: string | null
  prestamos: { id: number; titulo: string; tipo_item: string; fecha_devolucion: string | null }[]
  reservas_libro: { id: number; titulo: string | null; estado: 'pendiente' | 'en_cola'; fecha_retiro: string | null; posicion: number | null }[]
  reservas_sala: { id: number; sala: string | null; hora_inicio: string; hora_fin: string }[]
  multas: { cantidad: number; monto_total: number }
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

// GET /mi/reservas-libro/condiciones: lo que el portal muestra antes de confirmar una
// reserva. fecha_retiro (AAAA-MM-DD) es el plazo que tendría si reserva ahora.
export type CondicionesReservaLibro = {
  fecha_retiro: string
  dias_para_retirar: number
  /** Reservas activas a la vez, sumando las por retirar y las listas de espera. */
  maximo_por_persona: number
  /** Motivo por el que no puede reservar (multas pendientes), o null. */
  bloqueo: string | null
  /** Canceló hace poco desde el portal: el aviso y hasta cuándo espera (ISO), o null. */
  espera: string | null
  espera_hasta: string | null
}

// Tramo reservado dentro de una sala, en horario continuo (hora_inicio/hora_fin en
// formato "H:i"). Forma parte de Sala.tramos, la respuesta de
// SalaController::index()/PortalController::salas() (ver ReservaSalaService::vistaDelDia()).
/** Aviso al devolver tarde la llave de una sala (MultaService::atrasoDeSala()): el mesón decide si cursa la multa. */
export type AtrasoDevolucion = {
  reserva_id: number
  persona: string
  sala: string
  hora_fin: string
  minutos: number
  monto: number
  monto_provisional: boolean
}

/** La llave volvió antes de tiempo: lo que quedaba del bloque, para ofrecer liberarlo. */
export type TiempoSinUsar = {
  reserva_id: number
  sala: string
  /** «10:32»: cuándo volvió la llave. */
  devuelta: string
  hora_fin: string
  minutos: number
}

export type TramoReserva = {
  reserva_id: number
  hora_inicio: string
  hora_fin: string
  /** Hasta qué hora estaba reservada, si se liberó el resto al devolverla antes. */
  hora_fin_reservada?: string | null
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
  /** Solo en el portal: la hizo la persona o la incluyeron como integrante. */
  es_mia?: boolean
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
  // Día en que la biblioteca no atiende (config/biblioteca.php): no se reservan salas.
  cerrado: boolean
  // Lo que abarca la línea de tiempo (lo más amplio de la semana)…
  apertura: string
  cierre: string
  // …y lo que atiende la biblioteca ese día: fuera de eso no se reserva.
  atencion: [string, string] | null
  granularidad: number
  duracion_minima: number
  duracion_maxima: number
  cuota_diaria: number
  salas: Sala[]
}

export type SerieItem = {
  label: string
  value: number
  // Solo en el gráfico principal por días: fines de semana en tono claro, días sin atención rayados.
  finDeSemana?: boolean
  cerrado?: boolean
}

/** Feriado, vacaciones o cierre: de toda la biblioteca (sin ubicación) o de una ubicación. */
export type DiaSinAtencion = {
  id: number
  fecha: string
  ubicacion_id: number | null
  ubicacion?: Ubicacion | null
  motivo: string | null
}

export type TipoUsuario = 'estudiante' | 'docente' | 'funcionario'

/** Plazo, máximo a la vez y multa; null en tipo de usuario, material o ubicación = vale para todos. */
export type ReglaPrestamo = {
  id: number
  tipo_usuario: TipoUsuario | null
  tipo_material_id: number | null
  tipo_material?: Pick<TipoMaterial, 'id' | 'nombre'> | null
  ubicacion_id: number | null
  ubicacion?: Ubicacion | null
  dias_prestamo: number | null
  max_ejemplares: number | null
  multa_por_dia: number | null
  tope_multa: number | null
  prestable: boolean
}

/** Lo que el mesón sabe antes de prestar una copia a una persona (GET /prestamos/condiciones). */
export type CondicionesPrestamo = {
  regla: (Omit<ReglaPrestamo, 'tipo_material' | 'ubicacion'> & { descripcion: string }) | null
  prestable: boolean
  max_ejemplares: number
  libros_en_prestamo: number
  dias_prestamo: number | null
  fecha_devolucion: string | null
  multa_por_dia: number
  tope_multa: number | null
  motivo_rechazo: string | null
}

export type EstadoFasciculo = 'recibido' | 'esperado' | 'reclamado' | 'faltante'

/** Un número de una revista. */
export type Fasciculo = {
  id: number
  libro_id: number
  ejemplar_id: number | null
  ejemplar?: Pick<Ejemplar, 'id' | 'codigo_barras' | 'numero_copia' | 'disponible' | 'estado_proceso'> | null
  enumeracion: string | null
  cronologia: string | null
  fecha_publicacion: string | null
  fecha_esperada: string | null
  fecha_recepcion: string | null
  estado: EstadoFasciculo
  nota: string | null
  referencia_externa: string | null
}

/** Horario de atención por día ISO (1 = lunes ... 7 = domingo): [apertura, cierre] o null si no abre. */
export type HorarioBiblioteca = Record<string, [string, string] | null>

export type HoraPorSala = {
  sala: string
  horas: SerieItem[]
}

export type ReporteResumen = {
  rango: { desde: string; hasta: string }
  total: number
  totalAnterior: number
  promedioPorPeriodo: number
  categoriaMasFrecuente: string
  // Bloque de dos horas seguidas con más registros (null si no hay ninguno).
  horarioPico: { desde: string; hasta: string; value: number } | null
  horario: HorarioBiblioteca
  serie: SerieItem[]
  porCarrera: SerieItem[]
  porSexo: SerieItem[]
  porAnioIngreso: SerieItem[]
  porTipoUsuario: SerieItem[]
  porHora: SerieItem[]
  // Solo con contenido en su pestaña: préstamos (tipo y recurso), logias (sala) y libros.
  // Tipo: bibliografico | recursos_apoyo. Recurso: el tipo de material del catálogo o la clave de una categoría de recurso de apoyo.
  porTipoPrestamo: SerieItem[]
  porRecurso: SerieItem[]
  // Préstamos del catálogo por su tipo de material (Libro, Tesis, Juego educativo…).
  porTipoMaterial: SerieItem[]
  porSala: SerieItem[]
  porLibro: SerieItem[]
  porHoraPorSala: HoraPorSala[]
}

export type ModoVigencia = 'fecha_fija' | 'duracion' | 'sin_vencimiento'

/** Cuánto dura la cuenta de un tipo de usuario al renovarla (Administración › Vigencia de cuentas). */
export type PoliticaVigencia = {
  tipo: 'estudiante' | 'docente' | 'funcionario'
  modo: ModoVigencia
  mes: number | null
  dia: number | null
  anios: number | null
  descripcion: string
  /** Fecha (AAAA-MM-DD) que recibiría hoy una cuenta de este tipo al renovarse; null: no vence. */
  proximo: string | null
  cuentas_vigentes: number
  por_vencer_60_dias: number
}

export type ReporteOpciones = {
  carreras: string[]
  aniosIngreso: number[]
  sexos: string[]
  tiposUsuario: string[]
  /** Nombre de cada recurso que se presta: «libro» y las claves de las categorías de recursos de apoyo. */
  recursos: Record<string, string>
  /** Tipos de material del catálogo, para filtrar los préstamos. */
  tiposMaterial: { id: number; nombre: string }[]
}

export type Periodo = 'dia' | 'semana' | 'mes' | 'semestre' | 'anio'
export type ReporteTab = 'prestamos' | 'ingresos' | 'logias' | 'libros'

export type DeudaUsuario = {
  usuario_id: number
  nombre: string
  apellido: string
  rut: string
  email: string | null
  cantidad_multas: number
  monto_total: number
  desde: string
}

export type MultasResumen = {
  total_usuarios: number
  total_multas: number
  monto_total: number
  usuarios: DeudaUsuario[]
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

/** Una página del Registro absoluto (las operaciones vienen ordenadas por fecha, de la más nueva). */
export type RegistroAbsolutoResumen = {
  desde: string
  hasta: string
  total: number
  operaciones: OperacionRegistro[]
  current_page: number
  last_page: number
  per_page: number
  from: number | null
  to: number | null
}

/** Lo que hizo alguien en el sistema (Registro absoluto → Acciones del personal). */
export type AccionBitacora = {
  id: number
  fecha_hora: string
  actor: string
  actor_tipo: 'staff' | 'usuario' | 'sistema'
  modulo: string
  accion: string
  descripcion: string
  afectado: string | null
  datos: Record<string, unknown> | null
}

export type BitacoraResumen = {
  desde: string
  hasta: string
  total: number
  acciones: AccionBitacora[]
  current_page: number
  last_page: number
  per_page: number
  from: number | null
  to: number | null
}

export type BitacoraOpciones = {
  modulos: { clave: string; nombre: string }[]
  /** Incluye las cuentas eliminadas: sus acciones siguen en la bitácora. */
  personal: { id: number; nombre: string; activo: boolean; eliminada?: boolean }[]
}

/** Llegada a una sala: quiénes quedaron presentes recién y quiénes ya habían entrado hoy. */
export type AsistenciaSala = { registradas: string[]; ya_presentes: string[] }

/** Respuesta al modificar los integrantes de una reserva de sala. */
export type IntegrantesReserva = {
  reserva_id: number
  cantidad_personas: number
  personas: TramoReserva['personas']
  // Solo si la llegada ya estaba confirmada y se sumó alguien: quedó presente.
  asistencia: AsistenciaSala | null
}

export type OpcionesUsuarios = {
  carreras: string[]
  tipos_documento: { clave: 'rut' | 'pasaporte' | 'dni' | 'otro'; nombre: string }[]
  tipos: Usuario['tipo'][]
  sexos: string[]
}

export type EstadoFilaImportacion = 'nuevo' | 'actualizar' | 'sin_cambios' | 'duplicado' | 'invalido'

export type FilaImportacion = {
  fila: number
  estado: EstadoFilaImportacion
  datos: Partial<Usuario> & { rut: string }
  /** Solo en quien ya existe: lo que cambia, como campo → [antes, después]. */
  cambios: Partial<Record<keyof Usuario, [unknown, unknown]>>
  mensajes: string[]
  existente: Pick<Usuario, 'id' | 'rut' | 'nombre' | 'apellido'> | null
}

export type ResultadoImportacion = {
  resumen: { total: number; nuevos: number; actualizados: number; sin_cambios: number; duplicados: number; invalidos: number }
  filas: FilaImportacion[]
  creados?: number
  actualizados?: number
}

/** Respuesta por páginas de la API (paginate() de Laravel): una página de filas y el total. */
export type Paginado<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

/** Historial de libros: una fila de la lista (el detalle de préstamos se pide aparte). */
export type LibroHistorialItem = {
  libro: Pick<Libro, 'id' | 'titulo' | 'isbn' | 'clasificacion'>
  copias: number
  total_prestamos: number
}

export type VistaPrestamos = 'activos' | 'vencidos' | 'devueltos' | 'todos'

/** Listado de préstamos: la página de la pestaña elegida y cuántos hay en cada pestaña. */
export type ListadoPrestamos = Paginado<Prestamo> & { conteos: Record<VistaPrestamos, number> }

export type VistaReservas = 'activas' | 'finalizadas' | 'no_show' | 'canceladas' | 'todas'

/**
 * En qué está una reserva de sala: pendiente de llegada, en uso, con la llave sin
 * devolver (ya terminó su horario) o su estado final (ReservaSalaService::situacion()).
 */
export type SituacionReserva = 'pendiente' | 'en_uso' | 'sin_devolver' | 'finalizada' | 'no_show' | 'cancelada'

/** Una reserva de sala en el Listado de reservas (ReservaSalaService::paraListado()). */
export type ReservaListado = {
  id: number
  sala: Pick<Sala, 'id' | 'nombre' | 'tipo'> | null
  fecha: string
  hora_inicio: string
  hora_fin: string
  /** Hasta qué hora estaba reservada, si se liberó el resto al devolverla antes. */
  hora_fin_reservada: string | null
  duracion_minutos: number
  estado: 'activa' | 'finalizada' | 'no_show' | 'cancelada'
  situacion: SituacionReserva
  cantidad_personas: number
  titular: { rut: string; nombre: string | null }
  personas: TramoReserva['personas']
  prestado_por: string | null
  devuelto_por: string | null
  hora_prestamo_real: string | null
  hora_devolucion_real: string | null
  /** Cómo se entregó la llave: 'BC' (código de barras) o 'manual'. */
  via: string | null
  plazo_confirmacion: string
  created_at: string
}

/** Listado de reservas: la página de la pestaña elegida, cuántas hay en cada pestaña y las salas para filtrar. */
export type ListadoReservas = Paginado<ReservaListado> & {
  conteos: Record<VistaReservas, number>
  salas: Pick<Sala, 'id' | 'nombre' | 'tipo'>[]
}

