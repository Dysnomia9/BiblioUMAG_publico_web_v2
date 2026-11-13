<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import CabeceraDegradado from '@/components/ui/CabeceraDegradado.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { describirDocumento, errorDocumento, formatearInputIdentificador, TIPOS_DOCUMENTO, type TipoDocumento } from '@/utils/documento'
import type { OpcionesUsuarios, Usuario } from '@/types'

/**
 * La ficha de un usuario (crear o editar), con su confirmación. La usan
 * Administración → Usuarios y el mesón (Préstamos y Entradas): se abre con
 * abrirNuevo() o abrirEdicion() y avisa con «guardado» al terminar.
 */
const emit = defineEmits<{ guardado: [usuario: Usuario] }>()

const toast = useToast()
const auth = useAuthStore()

const opciones = ref<OpcionesUsuarios>({ carreras: [], tipos_documento: [], tipos: ['estudiante', 'docente', 'funcionario'], sexos: ['Femenino', 'Masculino', 'Otro'] })
const TIPO_LABEL: Record<Usuario['tipo'], string> = { estudiante: 'Estudiante', docente: 'Docente', funcionario: 'Funcionario' }

async function cargarOpciones() {
  try {
    const { data } = await api.get<OpcionesUsuarios>('/usuarios/opciones')
    opciones.value = data
  } catch {
    // Sin opciones, la carrera se escribe a mano.
  }
}
onMounted(cargarOpciones)

/** La fecha de vigencia la cambia a mano solo quien administra usuarios; el mesón la renueva. */
const editaVigencia = computed(() => auth.puede('usuarios.gestionar'))

type Ficha = {
  tipo_documento: TipoDocumento
  rut: string
  pais: string
  nombre: string
  apellido: string
  email: string
  /** Correos adicionales (+1): además del principal. */
  correos_adicionales: string[]
  tipo: Usuario['tipo']
  carrera: string
  anio_ingreso: string
  sexo: string
  /** Docentes y funcionarios. */
  unidad: string
  cargo: string
  activo: boolean
  /** Último día en que la cuenta sirve (AAAA-MM-DD); vacío: sin vencimiento. */
  vigente_hasta: string
  password: string
}

// Los docentes y funcionarios no tienen carrera ni año de ingreso: para ellos el formulario no los pide.
const fichaVacia = (tipo: Usuario['tipo'] = 'estudiante'): Ficha => ({
  tipo_documento: 'rut', rut: '', pais: '', nombre: '', apellido: '', email: '', correos_adicionales: [], tipo,
  carrera: '', anio_ingreso: tipo === 'estudiante' ? String(new Date().getFullYear()) : '', sexo: '', unidad: '', cargo: '', activo: true, vigente_hasta: '', password: '',
})

const form = reactive({
  abierto: false,
  id: null as number | null,
  ficha: fichaVacia(),
  original: null as Ficha | null,
  errores: {} as Record<string, string>,
  confirmando: false,
  guardando: false,
})

function fichaDe(u: Usuario): Ficha {
  return {
    tipo_documento: u.tipo_documento ?? 'rut',
    rut: u.rut,
    pais: u.pais ?? '',
    nombre: u.nombre,
    apellido: u.apellido,
    email: u.email ?? '',
    correos_adicionales: (u.correos ?? []).map((c) => c.email),
    tipo: u.tipo,
    carrera: u.carrera ?? '',
    anio_ingreso: u.anio_ingreso ? String(u.anio_ingreso) : '',
    sexo: u.sexo ?? '',
    unidad: u.unidad ?? '',
    cargo: u.cargo ?? '',
    activo: u.activo,
    vigente_hasta: u.vigente_hasta ? u.vigente_hasta.slice(0, 10) : '',
    password: '',
  }
}

/** Con `documento`, la ficha parte con ese RUT o documento ya escrito (lo que se buscó en el mesón). */
function abrirNuevo(tipo: Usuario['tipo'] = 'estudiante', documento?: { rut: string; tipo_documento: TipoDocumento }) {
  const ficha = fichaVacia(tipo)
  if (documento) Object.assign(ficha, documento)
  Object.assign(form, { abierto: true, id: null, ficha, original: null, errores: {}, confirmando: false })
  nuevoCorreo.value = ''
  pasoActivo.value = 0
  verPassword.value = false
}

/** Carrera y año de ingreso solo para estudiantes (o si la persona ya los tiene guardados). */
/** Unidad y cargo para docentes y funcionarios (o si la persona ya los tiene). */
const pideUnidad = computed(() => form.ficha.tipo !== 'estudiante' || !!form.ficha.unidad || !!form.ficha.cargo)
const pideCarrera = computed(() => form.ficha.tipo === 'estudiante' || !!form.ficha.carrera || !!form.ficha.anio_ingreso)
const NOMBRE_TIPO: Record<Usuario['tipo'], string> = { estudiante: 'estudiante', docente: 'docente', funcionario: 'funcionario' }

/** Secciones de la ficha, numeradas según cuáles se muestran. */
const secciones = computed(() => ['Identidad', 'Datos personales', 'Contacto', ...(pideCarrera.value ? ['Académico'] : []), ...(pideUnidad.value ? ['Trabajo'] : []), 'Cuenta'])
const CORTO: Record<string, string> = { 'Datos personales': 'Datos', Académico: 'Información académica' }
const pasos = computed(() => secciones.value.map((s) => CORTO[s] ?? s))
const TITULO_SECCION: Record<string, string> = { Académico: 'Información académica' }

// Las fichas de la cabecera llevan a su sección; la última elegida queda marcada.
const pasoActivo = ref(0)
function irAPaso(i: number) {
  pasoActivo.value = i
  document.getElementById(`ficha-seccion-${i + 1}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/**
 * La contraseña del portal se pone al crear la cuenta. La de una cuenta que ya existe
 * solo la cambia quien administra usuarios: el mesón la ve bloqueada.
 */
const editaPassword = computed(() => !form.id || auth.puede('usuarios.gestionar'))
const verPassword = ref(false)

// Correos adicionales: se agregan de a uno y se pueden quitar.
const nuevoCorreo = ref('')
function agregarCorreo() {
  const correo = nuevoCorreo.value.trim().toLowerCase()
  if (!correo) return
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
    form.errores.correos_adicionales = 'El correo no es válido.'
    return
  }
  if (correo === form.ficha.email.trim().toLowerCase() || form.ficha.correos_adicionales.includes(correo)) {
    form.errores.correos_adicionales = 'Ese correo ya está en la ficha.'
    return
  }
  form.ficha.correos_adicionales.push(correo)
  nuevoCorreo.value = ''
  delete form.errores.correos_adicionales
}
function quitarCorreo(correo: string) {
  form.ficha.correos_adicionales = form.ficha.correos_adicionales.filter((c) => c !== correo)
}

function abrirEdicion(u: Usuario) {
  Object.assign(form, { abierto: true, id: u.id, ficha: fichaDe(u), original: fichaDe(u), errores: {}, confirmando: false })
  nuevoCorreo.value = ''
  pasoActivo.value = 0
  verPassword.value = false
}

defineExpose({ abrirNuevo, abrirEdicion })

function limpiarError(campo: string) {
  delete form.errores[campo]
}

function elegirTipoDocumento(tipo: TipoDocumento) {
  if (form.ficha.tipo_documento === tipo) return
  form.ficha.tipo_documento = tipo
  form.ficha.rut = ''
  delete form.errores.rut
}

const ETIQUETAS: Record<keyof Ficha, string> = {
  tipo_documento: 'Tipo de documento', rut: 'RUT / documento', pais: 'País', nombre: 'Nombre', apellido: 'Apellido', email: 'Correo',
  correos_adicionales: 'Correos adicionales', tipo: 'Tipo', carrera: 'Carrera', anio_ingreso: 'Año de ingreso', sexo: 'Sexo', unidad: 'Unidad', cargo: 'Cargo', activo: 'Estado', vigente_hasta: 'Cuenta vigente hasta', password: 'Contraseña del portal',
}

function mostrar(campo: keyof Ficha, f: Ficha): string {
  if (campo === 'tipo_documento') return TIPOS_DOCUMENTO[f.tipo_documento]
  if (campo === 'tipo') return TIPO_LABEL[f.tipo]
  if (campo === 'activo') return f.activo ? 'Activo' : 'Inactivo'
  if (campo === 'password') return f.password ? 'nueva (asignada)' : '—'
  if (campo === 'correos_adicionales') return f.correos_adicionales.length ? f.correos_adicionales.join(', ') : '—'
  return String(f[campo] || '—')
}

/** Lo que cambia respecto a lo guardado (solo al editar). */
const cambios = computed(() => {
  const original = form.original
  if (!original) return []
  return (Object.keys(ETIQUETAS) as (keyof Ficha)[])
    .filter((campo) => (campo === 'password' ? !!form.ficha.password : String(form.ficha[campo] ?? '').trim() !== String(original[campo] ?? '').trim()))
    .map((campo) => ({ campo: ETIQUETAS[campo], antes: campo === 'password' ? '—' : mostrar(campo, original), despues: mostrar(campo, form.ficha) }))
})

const cambiaDocumento = computed(() => !!form.original && (form.original.rut !== form.ficha.rut || form.original.tipo_documento !== form.ficha.tipo_documento))

// Habilitar lo puede cualquiera que edite; desactivar, solo el Administrador/a.
const bloqueaDesactivar = computed(() => !!form.original?.activo && !auth.puede('usuarios.desactivar'))

function validar(): boolean {
  const errores: Record<string, string> = {}
  const f = form.ficha
  const errorDoc = errorDocumento(f.rut, f.tipo_documento)
  if (errorDoc) errores.rut = errorDoc
  if (!f.nombre.trim()) errores.nombre = 'Falta el nombre.'
  if (!f.apellido.trim()) errores.apellido = 'Falta el apellido.'
  if (f.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) errores.email = 'El correo no es válido.'
  if (nuevoCorreo.value.trim()) errores.correos_adicionales = 'Falta agregar el correo que escribiste (botón «Agregar») o borrarlo.'
  if (f.anio_ingreso && !/^\d{4}$/.test(f.anio_ingreso)) errores.anio_ingreso = 'Cuatro dígitos (ej. 2026).'
  if (f.password && f.password.length < 6) errores.password = 'Al menos 6 caracteres.'
  form.errores = errores
  return !Object.keys(errores).length
}

function pedirConfirmacion() {
  if (!validar()) {
    toast.error('Revisa los campos marcados')
    return
  }
  if (form.id && !cambios.value.length) {
    toast.info('No hay cambios que guardar')
    return
  }
  form.confirmando = true
}

async function guardar() {
  const f = form.ficha
  const datos = {
    tipo_documento: f.tipo_documento,
    rut: f.rut,
    pais: f.tipo_documento === 'rut' ? null : f.pais.trim() || null,
    nombre: f.nombre.trim(),
    apellido: f.apellido.trim(),
    email: f.email.trim() || null,
    correos_adicionales: f.correos_adicionales,
    tipo: f.tipo,
    carrera: pideCarrera.value ? f.carrera.trim() || null : null,
    anio_ingreso: pideCarrera.value && f.anio_ingreso ? Number(f.anio_ingreso) : null,
    sexo: f.sexo || null,
    unidad: pideUnidad.value ? f.unidad.trim() || null : null,
    cargo: pideUnidad.value ? f.cargo.trim() || null : null,
    activo: f.activo,
    vigente_hasta: editaVigencia.value ? f.vigente_hasta || undefined : undefined,
    password: editaPassword.value ? f.password || undefined : undefined,
  }
  form.guardando = true
  try {
    let guardado: Usuario
    if (form.id) {
      guardado = (await api.put<Usuario>(`/usuarios/${form.id}`, datos)).data
      toast.success(`Datos de ${datos.nombre} ${datos.apellido} actualizados`)
    } else {
      guardado = (await api.post<Usuario>('/usuarios', datos)).data
      toast.success(`${datos.nombre} ${datos.apellido} quedó registrado/a como ${NOMBRE_TIPO[datos.tipo]}`)
    }
    form.confirmando = false
    form.abierto = false
    emit('guardado', guardado)
    cargarOpciones()
  } catch (e: any) {
    const errores = e?.response?.data?.errors as Record<string, string[]> | undefined
    if (errores) form.errores = Object.fromEntries(Object.entries(errores).map(([campo, m]) => [campo, m[0]]))
    form.confirmando = false
    toast.error(mensajeDeError(e, 'No se pudo guardar'))
  } finally {
    form.guardando = false
  }
}

const clasesInput = (campo: string) =>
  `w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none transition focus:ring-2 ${form.errores[campo] ? 'border-red-400 focus:ring-red-200' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'}`
const ETIQUETA = 'mb-1 block text-xs font-semibold text-gray-700'
</script>

<template>
    <Modal
      :abierto="form.abierto"
      :titulo="form.id ? `Editar ${NOMBRE_TIPO[form.ficha.tipo]}` : `Nuevo ${NOMBRE_TIPO[form.ficha.tipo]}`"
      ancho="lg"
      destacado
      degradado
      :bloqueado="form.guardando"
      @cerrar="form.abierto = false"
    >
      <template #cabecera>
        <CabeceraDegradado
          :etiqueta="form.id ? 'Editar usuario' : 'Nuevo usuario'"
          :titulo="form.id ? `${form.original?.nombre} ${form.original?.apellido}` : `Nuevo ${NOMBRE_TIPO[form.ficha.tipo]}`"
          :descripcion="form.id ? `${TIPO_LABEL[form.ficha.tipo]} · los cambios se guardan recién al confirmar.` : 'Quedará habilitado para entradas, préstamos, reservas y el portal.'"
          icono="M4.260 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.410 60.460 60.460 0 00-.491-6.347m-15.482 0a50.570 50.570 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.840c-.896.248-1.783.520-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.740-3.342"
          :pasos="pasos"
          :paso-activo="pasoActivo"
          :bloqueado="form.guardando"
          @cerrar="form.abierto = false"
          @ir-a-paso="irAPaso"
        />
      </template>

      <div class="space-y-3.5">
        <template v-for="(seccion, i) in secciones" :key="seccion">
          <div :id="`ficha-seccion-${i + 1}`" class="flex scroll-mt-2 items-center gap-2.5" :class="i ? 'pt-1' : ''">
            <span class="flex h-5 w-5 items-center justify-center rounded-md bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ i + 1 }}</span>
            <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-indigo-950/70">{{ TITULO_SECCION[seccion] ?? seccion }}</span>
            <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
          </div>

          <!-- Identidad -->
          <template v-if="seccion === 'Identidad'">
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 sm:grid-cols-4" role="radiogroup" aria-label="Tipo de documento">
              <button
                v-for="(nombre, clave) in TIPOS_DOCUMENTO"
                :key="clave"
                type="button"
                role="radio"
                :aria-checked="form.ficha.tipo_documento === clave"
                class="rounded-md px-2 py-1.5 text-xs font-semibold transition-colors"
                :class="form.ficha.tipo_documento === clave ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                @click="elegirTipoDocumento(clave)"
              >
                {{ clave === 'dni' ? 'DNI / cédula' : nombre }}
              </button>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label for="est-rut" :class="ETIQUETA">
                  {{ form.ficha.tipo_documento === 'rut' ? 'RUT' : form.ficha.tipo_documento === 'pasaporte' ? 'N.º de pasaporte' : 'N.º de documento' }}
                </label>
                <input
                  id="est-rut"
                  :value="form.ficha.rut"
                  :placeholder="form.ficha.tipo_documento === 'rut' ? '12.345.678-5' : form.ficha.tipo_documento === 'pasaporte' ? 'Ej: FT482913' : 'Ej: 38456123'"
                  :maxlength="form.ficha.tipo_documento === 'rut' ? 12 : 20"
                  autocomplete="off"
                  :class="[clasesInput('rut'), 'font-mono']"
                  @input="form.ficha.rut = formatearInputIdentificador($event, form.ficha.tipo_documento); limpiarError('rut')"
                />
                <p v-if="form.errores.rut" class="mt-1 text-xs text-red-600">{{ form.errores.rut }}</p>
              </div>
              <div v-if="form.ficha.tipo_documento !== 'rut'">
                <label for="est-pais" :class="ETIQUETA">País <span class="font-normal text-gray-400">(opcional)</span></label>
                <input id="est-pais" v-model="form.ficha.pais" maxlength="100" placeholder="Ej: Argentina" :class="clasesInput('pais')" />
              </div>
            </div>
          </template>

          <!-- Datos personales -->
          <template v-else-if="seccion === 'Datos personales'">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_9rem]">
              <div>
                <label for="est-nombre" :class="ETIQUETA">Nombre</label>
                <input id="est-nombre" v-model="form.ficha.nombre" maxlength="255" :class="clasesInput('nombre')" @input="limpiarError('nombre')" />
                <p v-if="form.errores.nombre" class="mt-1 text-xs text-red-600">{{ form.errores.nombre }}</p>
              </div>
              <div>
                <label for="est-apellido" :class="ETIQUETA">Apellido</label>
                <input id="est-apellido" v-model="form.ficha.apellido" maxlength="255" :class="clasesInput('apellido')" @input="limpiarError('apellido')" />
                <p v-if="form.errores.apellido" class="mt-1 text-xs text-red-600">{{ form.errores.apellido }}</p>
              </div>
              <div>
                <label for="est-sexo" :class="ETIQUETA">Sexo <span class="font-normal text-gray-400">(opc.)</span></label>
                <select id="est-sexo" v-model="form.ficha.sexo" :class="clasesInput('sexo')">
                  <option value="">Sin informar</option>
                  <option v-for="s in opciones.sexos" :key="s" :value="s">{{ s }}</option>
                </select>
              </div>
            </div>
            <div v-if="form.id" class="sm:w-1/2">
              <label for="est-tipo" :class="ETIQUETA">Tipo</label>
              <select id="est-tipo" v-model="form.ficha.tipo" :class="clasesInput('tipo')">
                <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}</option>
              </select>
            </div>
          </template>

          <!-- Contacto -->
          <template v-else-if="seccion === 'Contacto'">
            <div>
              <label for="est-email" :class="ETIQUETA">Correo <span class="font-normal text-gray-400">(opcional)</span></label>
              <input id="est-email" v-model="form.ficha.email" type="email" maxlength="255" placeholder="nombre.apellido@umag.cl" :class="clasesInput('email')" @input="limpiarError('email')" />
              <p v-if="form.errores.email" class="mt-1 text-xs text-red-600">{{ form.errores.email }}</p>
            </div>
            <div>
              <label for="est-correo-extra" :class="ETIQUETA">Correos adicionales <span class="font-normal text-gray-400">(opcional)</span></label>
              <ul v-if="form.ficha.correos_adicionales.length" class="mb-2 flex flex-wrap gap-1.5">
                <li v-for="c in form.ficha.correos_adicionales" :key="c" class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs text-indigo-900">
                  {{ c }}
                  <button type="button" class="text-indigo-500 hover:text-red-600" :aria-label="`Quitar ${c}`" @click="quitarCorreo(c)">×</button>
                </li>
              </ul>
              <div class="flex gap-2">
                <input
                  id="est-correo-extra"
                  v-model="nuevoCorreo"
                  type="email"
                  maxlength="255"
                  placeholder="otro.correo@gmail.com"
                  :class="clasesInput('correos_adicionales')"
                  @keydown.enter.prevent="agregarCorreo"
                  @input="limpiarError('correos_adicionales')"
                />
                <button type="button" class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 transition-colors hover:bg-gray-50" @click="agregarCorreo">+ Agregar</button>
              </div>
              <p v-if="form.errores.correos_adicionales" class="mt-1 text-xs text-red-600">{{ form.errores.correos_adicionales }}</p>
              <p v-else class="mt-1 text-[11px] text-gray-500">Sirven para reconocerla al entrar con Google o el directorio. Las notificaciones van al correo principal.</p>
            </div>
          </template>

          <!-- Información académica -->
          <template v-else-if="seccion === 'Académico'">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
              <div>
                <label for="est-carrera" :class="ETIQUETA">Carrera <span class="font-normal text-gray-400">(opcional)</span></label>
                <input id="est-carrera" v-model="form.ficha.carrera" list="est-carreras" maxlength="255" placeholder="Elige una o escribe una nueva" :class="clasesInput('carrera')" />
                <datalist id="est-carreras">
                  <option v-for="c in opciones.carreras" :key="c" :value="c" />
                </datalist>
              </div>
              <div>
                <label for="est-anio" :class="ETIQUETA">Año de ingreso</label>
                <input id="est-anio" v-model="form.ficha.anio_ingreso" inputmode="numeric" maxlength="4" placeholder="2026" :class="clasesInput('anio_ingreso')" @input="limpiarError('anio_ingreso')" />
                <p v-if="form.errores.anio_ingreso" class="mt-1 text-xs text-red-600">{{ form.errores.anio_ingreso }}</p>
              </div>
            </div>
          </template>

          <!-- Trabajo -->
          <template v-else-if="seccion === 'Trabajo'">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label for="est-unidad" :class="ETIQUETA">Unidad o departamento <span class="font-normal text-gray-400">(opcional)</span></label>
                <input id="est-unidad" v-model="form.ficha.unidad" maxlength="255" placeholder="Ej: Departamento de Ciencias" :class="clasesInput('unidad')" />
              </div>
              <div>
                <label for="est-cargo" :class="ETIQUETA">Cargo <span class="font-normal text-gray-400">(opcional)</span></label>
                <input id="est-cargo" v-model="form.ficha.cargo" maxlength="255" placeholder="Ej: Profesor adjunto" :class="clasesInput('cargo')" />
              </div>
            </div>
          </template>

          <!-- Cuenta -->
          <div v-else-if="seccion === 'Cuenta'" class="space-y-3 rounded-xl border border-gray-200 bg-gray-50/70 p-3">
            <div>
              <label for="est-password" :class="ETIQUETA">Contraseña del portal <span class="font-normal text-gray-400">(opcional)</span></label>
              <div class="relative">
                <input
                  id="est-password"
                  v-model="form.ficha.password"
                  :type="verPassword && editaPassword ? 'text' : 'password'"
                  autocomplete="new-password"
                  :disabled="!editaPassword"
                  :placeholder="!editaPassword ? '••••••••' : form.id ? 'Déjala en blanco para no cambiarla' : 'Dejar vacía'"
                  :class="[clasesInput('password'), 'pr-10 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400']"
                  @input="limpiarError('password')"
                />
                <button
                  v-if="editaPassword"
                  type="button"
                  class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-gray-400 hover:text-gray-700"
                  :aria-label="verPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                  @click="verPassword = !verPassword"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path v-if="verPassword" stroke-linecap="round" stroke-linejoin="round" d="M3.980 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.500 12 19.500c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.450 10.450 0 0112 4.500c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.650 3.650m7.894 7.894L21 21m-3.228-3.228l-3.650-3.650m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.880 9.880" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.510 7.360 4.500 12 4.500c4.638 0 8.573 3.007 9.963 7.178.070.207.070.431 0 .639C20.577 16.490 16.640 19.500 12 19.500c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </button>
                <svg v-else class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.500 10.500V6.750a4.500 4.500 0 10-9 0v3.750m-.750 11.250h10.500a2.250 2.250 0 002.250-2.250v-6.750a2.250 2.250 0 00-2.250-2.250H6.750a2.250 2.250 0 00-2.250 2.250v6.750a2.250 2.250 0 002.250 2.250z" />
                </svg>
              </div>
              <p v-if="form.errores.password" class="mt-1 text-xs text-red-600">{{ form.errores.password }}</p>
              <p v-else class="mt-1 flex items-center gap-1.5 text-[11px] text-gray-500">
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.250 11.250l.041-.020a.750.750 0 011.063.852l-.708 2.836a.750.750 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.750h.008v.008H12V8.250z" /></svg>
                {{ !editaPassword ? 'La contraseña de una cuenta existente solo la cambia un administrador de usuarios.' : form.id ? 'En blanco, no se cambia.' : 'Sin ella, entra con el login institucional.' }}
              </p>
            </div>
            <div v-if="form.id && editaVigencia">
              <label for="usuario-vigente" :class="ETIQUETA">Cuenta vigente hasta</label>
              <input id="usuario-vigente" v-model="form.ficha.vigente_hasta" type="date" :class="[clasesInput('vigente_hasta'), 'sm:w-1/2']" @input="limpiarError('vigente_hasta')" />
              <p v-if="form.errores.vigente_hasta" class="mt-1 text-xs text-red-600">{{ form.errores.vigente_hasta }}</p>
              <p v-else class="mt-1 text-[11px] text-gray-500">Último día en que la cuenta sirve. Pasada esa fecha queda vencida hasta que se renueve.</p>
            </div>
            <div v-if="form.id">
              <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border bg-white px-3 py-2 text-sm" :class="[form.ficha.activo ? 'border-indigo-300' : 'border-gray-200', bloqueaDesactivar ? 'opacity-60' : '']">
                <input v-model="form.ficha.activo" type="checkbox" :disabled="bloqueaDesactivar" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                <span>
                  <span class="block font-semibold text-gray-900">Activo</span>
                  <span class="block text-xs text-gray-500">Si se desactiva, no puede registrar entradas, pedir préstamos ni entrar al portal.</span>
                </span>
              </label>
              <p v-if="bloqueaDesactivar" class="mt-1 text-xs text-gray-500">Solo un Administrador/a puede desactivar a un usuario.</p>
            </div>
          </div>
        </template>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100"
          :disabled="form.guardando"
          @click="form.abierto = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-[1.4] rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700 disabled:opacity-60"
          :disabled="form.guardando"
          @click="pedirConfirmacion"
        >
          {{ form.id ? 'Revisar cambios' : 'Revisar y crear' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="form.confirmando"
      :titulo="form.id ? '¿Guardar estos cambios?' : `¿Crear ${form.ficha.tipo === 'funcionario' ? 'este' : 'este'} ${NOMBRE_TIPO[form.ficha.tipo]}?`"
      :confirmar="form.id ? 'Sí, guardar cambios' : 'Sí, crear'"
      destacado
      :cargando="form.guardando"
      @confirmar="guardar"
      @cancelar="form.confirmando = false"
    >
      <template v-if="form.id">
        <p class="mb-2">{{ form.original?.nombre }} {{ form.original?.apellido }}:</p>
        <ul class="space-y-1.5">
          <li v-for="c in cambios" :key="c.campo">
            {{ c.campo }}: <span class="line-through">{{ c.antes }}</span> → <strong class="text-gray-900">{{ c.despues }}</strong>
          </li>
        </ul>
        <p v-if="cambiaDocumento" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-amber-800">
          Cambia el número con que se identifica en el mesón, las salas y el portal. Su historial (entradas, préstamos, multas) se conserva.
        </p>
        <p v-if="form.original?.activo && !form.ficha.activo" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-red-700">
          Quedará inactivo: no podrá registrar entradas, pedir préstamos ni entrar al portal hasta que se reactive.
        </p>
      </template>
      <template v-else>
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-gray-50 px-4 py-3">
          <dt>Documento</dt>
          <dd class="font-medium text-gray-900">{{ describirDocumento(form.ficha.rut, form.ficha.tipo_documento, form.ficha.pais.trim() || null) }}</dd>
          <dt>Nombre</dt>
          <dd class="font-medium text-gray-900">{{ form.ficha.nombre }} {{ form.ficha.apellido }}</dd>
          <dt>Tipo</dt>
          <dd class="text-gray-900">{{ TIPO_LABEL[form.ficha.tipo] }}</dd>
          <template v-if="form.ficha.unidad || form.ficha.cargo"><dt>Unidad y cargo</dt><dd class="text-gray-900">{{ [form.ficha.unidad, form.ficha.cargo].filter(Boolean).join(' · ') }}</dd></template>
          <template v-if="form.ficha.carrera"><dt>Carrera</dt><dd class="text-gray-900">{{ form.ficha.carrera }}<template v-if="form.ficha.anio_ingreso"> · {{ form.ficha.anio_ingreso }}</template></dd></template>
          <template v-if="form.ficha.email"><dt>Correo</dt><dd class="text-gray-900">{{ form.ficha.email }}</dd></template>
          <template v-if="form.ficha.correos_adicionales.length"><dt>Otros correos</dt><dd class="text-gray-900">{{ form.ficha.correos_adicionales.join(', ') }}</dd></template>
          <dt>Portal</dt>
          <dd class="text-gray-900">{{ form.ficha.password ? 'con contraseña asignada' : 'sin contraseña (login institucional)' }}</dd>
        </dl>
      </template>
    </DialogoConfirmacion>
</template>
