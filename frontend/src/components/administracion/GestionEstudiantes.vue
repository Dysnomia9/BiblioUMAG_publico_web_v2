<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import CargaMasivaEstudiantes from '@/components/administracion/CargaMasivaEstudiantes.vue'
import Paginacion from '@/components/ui/Paginacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useListadoPaginado } from '@/composables/useListadoPaginado'
import { useAuthStore } from '@/stores/auth'
import { describirDocumento, errorDocumento, formatearInputIdentificador, TIPOS_DOCUMENTO, type TipoDocumento } from '@/utils/documento'
import type { OpcionesUsuarios, Paginado, Usuario } from '@/types'

const toast = useToast()
const auth = useAuthStore()

const opciones = ref<OpcionesUsuarios>({ carreras: [], tipos_documento: [], tipos: ['estudiante', 'docente', 'funcionario'], sexos: ['Femenino', 'Masculino', 'Otro'] })

const filtros = reactive({ q: '', tipo: 'estudiante', carrera: '' })

const TIPO_LABEL: Record<Usuario['tipo'], string> = { estudiante: 'Estudiante', docente: 'Docente', funcionario: 'Funcionario' }
const ETIQUETA_DOCUMENTO = { rut: 'RUT', pasaporte: 'Pasaporte', dni: 'DNI', otro: 'Otro' } as const

function estadoDe(u: Usuario) {
  if (u.activo) return { texto: 'Activo', clase: 'border-emerald-300 bg-emerald-50/60 text-emerald-700' }
  if (u.inactividad === 'vencida') return { texto: 'Vencida', clase: 'border-amber-300 bg-amber-50/60 text-amber-800' }
  return { texto: 'Inactivo', clase: 'border-red-300 bg-red-50/60 text-red-700' }
}

// El servidor entrega de a 25: con el reporte de matrícula son miles de estudiantes.
// Tras guardar o tras una carga masiva se recarga la misma página.
const { filas: usuarios, total, porPagina, pagina, cargando, cargar, reiniciar } = useListadoPaginado<Usuario>(async (p) => {
  try {
    const { data } = await api.get<Paginado<Usuario>>('/usuarios', {
      params: {
        q: filtros.q.trim() || undefined,
        tipo: filtros.tipo || undefined,
        carrera: filtros.carrera || undefined,
        page: p,
      },
    })
    return data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cargar el listado de estudiantes'))
    throw e
  }
})

async function cargarOpciones() {
  try {
    const { data } = await api.get<OpcionesUsuarios>('/usuarios/opciones')
    opciones.value = data
  } catch {
    // Sin opciones, la carrera se escribe a mano.
  }
}

let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch(filtros, () => {
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(reiniciar, 300)
}, { deep: true })

onMounted(() => {
  cargar()
  cargarOpciones()
})

// ---------- Crear / editar ----------
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

function abrirNuevo(tipo: Usuario['tipo'] = 'estudiante') {
  Object.assign(form, { abierto: true, id: null, ficha: fichaVacia(tipo), original: null, errores: {}, confirmando: false })
  nuevoCorreo.value = ''
}

/** Carrera y año de ingreso solo para estudiantes (o si la persona ya los tiene guardados). */
/** Unidad y cargo para docentes y funcionarios (o si la persona ya los tiene). */
const pideUnidad = computed(() => form.ficha.tipo !== 'estudiante' || !!form.ficha.unidad || !!form.ficha.cargo)
const pideCarrera = computed(() => form.ficha.tipo === 'estudiante' || !!form.ficha.carrera || !!form.ficha.anio_ingreso)
const NOMBRE_TIPO: Record<Usuario['tipo'], string> = { estudiante: 'estudiante', docente: 'docente', funcionario: 'funcionario' }

/** Secciones de la ficha, numeradas según cuáles se muestran. */
const secciones = computed(() => ['Identidad', 'Datos personales', 'Contacto', ...(pideCarrera.value ? ['Académico'] : []), ...(pideUnidad.value ? ['Trabajo'] : []), 'Cuenta'])
const numero = (seccion: string) => secciones.value.indexOf(seccion) + 1

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
}

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
    vigente_hasta: f.vigente_hasta || undefined,
    password: f.password || undefined,
  }
  form.guardando = true
  try {
    if (form.id) {
      await api.put(`/usuarios/${form.id}`, datos)
      toast.success(`Datos de ${datos.nombre} ${datos.apellido} actualizados`)
    } else {
      await api.post('/usuarios', datos)
      toast.success(`${datos.nombre} ${datos.apellido} quedó registrado/a como ${NOMBRE_TIPO[datos.tipo]}`)
    }
    form.confirmando = false
    form.abierto = false
    await Promise.all([cargar(), cargarOpciones()])
  } catch (e: any) {
    const errores = e?.response?.data?.errors as Record<string, string[]> | undefined
    if (errores) form.errores = Object.fromEntries(Object.entries(errores).map(([campo, m]) => [campo, m[0]]))
    form.confirmando = false
    toast.error(mensajeDeError(e, 'No se pudo guardar'))
  } finally {
    form.guardando = false
  }
}

// ---------- Carga masiva ----------
const cargaAbierta = ref(false)

const clasesInput = (campo: string) =>
  `w-full px-3 py-2.5 border rounded-lg focus:ring-2 outline-none text-sm ${form.errores[campo] ? 'border-red-400 focus:ring-red-200' : 'border-gray-300 focus:ring-indigo-500'}`
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="flex flex-col gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Usuarios: estudiantes, docentes y funcionarios
        </h3>
        <p class="mt-0.5 max-w-xl pl-3 text-xs text-gray-500">
          Registra estudiantes, docentes y funcionarios (con RUT o, si son extranjeros, con pasaporte o DNI), corrige sus datos o cárgalos en forma masiva
          desde una planilla Excel o CSV. Todo cambio pide confirmación y queda en el Registro absoluto.
        </p>
      </div>
      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50"
          @click="cargaAbierta = true"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.500v2.250A2.250 2.250 0 005.250 21h13.500A2.250 2.250 0 0021 18.750V16.500m-13.500-9L12 3m0 0l4.500 4.500M12 3v13.500" /></svg>
          Carga masiva (Excel/CSV)
        </button>
        <!-- Un solo bloque para crear: se elige el tipo de persona. -->
        <div class="inline-flex overflow-hidden rounded-lg bg-indigo-600 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)]" role="group" aria-label="Crear usuario nuevo">
          <span class="flex items-center whitespace-nowrap bg-indigo-700/60 px-2.5 text-xs" aria-hidden="true">+ Nuevo</span>
          <button v-for="t in (['estudiante', 'docente', 'funcionario'] as const)" :key="t" type="button" class="border-l border-white/20 px-3 py-2 transition-colors first-of-type:border-l-0 hover:bg-indigo-700" :aria-label="`Nuevo ${NOMBRE_TIPO[t]}`" @click="abrirNuevo(t)">
            {{ TIPO_LABEL[t] }}
          </button>
        </div>
      </div>
    </header>

    <div class="grid grid-cols-1 gap-2 border-b border-gray-100 p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
      <div class="relative">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
        <input
          v-model="filtros.q"
          type="search"
          placeholder="Buscar por nombre, RUT, pasaporte o carrera"
          aria-label="Buscar estudiantes"
          class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
        />
      </div>
      <select v-model="filtros.tipo" aria-label="Filtrar por tipo" class="rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Todos los tipos</option>
        <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}s</option>
      </select>
      <select v-model="filtros.carrera" aria-label="Filtrar por carrera" class="rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Todas las carreras</option>
        <option v-for="c in opciones.carreras" :key="c" :value="c">{{ c }}</option>
      </select>
    </div>

    <p class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">
      <template v-if="cargando">Cargando…</template>
      <template v-else><strong class="text-gray-900">{{ total.toLocaleString('es-CL') }}</strong> {{ total === 1 ? 'persona' : 'personas' }}</template>
    </p>

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Documento</th>
            <th class="px-4 py-2.5">Nombre</th>
            <th class="hidden px-4 py-2.5 md:table-cell">Carrera</th>
            <th class="hidden px-4 py-2.5 sm:table-cell">Estado</th>
            <th class="px-4 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="u in usuarios" :key="u.id" class="even:bg-gray-50/50">
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span class="mr-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="u.tipo_documento && u.tipo_documento !== 'rut' ? 'bg-amber-50 text-amber-800' : 'bg-indigo-50 text-indigo-700'">{{ ETIQUETA_DOCUMENTO[u.tipo_documento ?? 'rut'] }}</span>
              <span class="font-mono text-xs text-gray-800">{{ u.rut }}</span>
              <span v-if="u.pais && u.tipo_documento !== 'rut'" class="mt-0.5 block text-[11px] text-gray-500">{{ u.pais }}</span>
            </td>
            <td class="px-4 py-2.5">
              <span class="block text-sm font-bold leading-snug text-gray-900">{{ u.nombre }} {{ u.apellido }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                <span class="font-semibold text-gray-600">{{ TIPO_LABEL[u.tipo] }}</span>
                <template v-if="u.email">
                  <span class="h-3 w-px bg-gray-300" aria-hidden="true"></span>
                  <span class="break-all text-indigo-700">{{ u.email }}</span>
                </template>
                <span v-if="u.correos?.length" class="rounded bg-indigo-50 px-1 text-indigo-700" :title="u.correos.map((c) => c.email).join(', ')">+{{ u.correos.length }}</span>
              </span>
            </td>
            <td class="hidden px-4 py-2.5 text-sm text-gray-700 md:table-cell">
              {{ u.carrera ?? u.unidad ?? '—' }}<span v-if="u.anio_ingreso" class="text-xs text-gray-400"> · {{ u.anio_ingreso }}</span>
            </td>
            <td class="hidden px-4 py-2.5 whitespace-nowrap sm:table-cell">
              <span class="inline-block rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="estadoDe(u).clase">{{ estadoDe(u).texto }}</span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50" @click="abrirEdicion(u)">Editar</button>
            </td>
          </tr>
          <tr v-if="!cargando && !usuarios.length">
            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Sin personas que coincidan con los filtros.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Paginacion v-model="pagina" class="border-t border-gray-100 px-4 py-2" :total="total" :por-pagina="porPagina" singular="persona" plural="personas" />

    <Modal
      :abierto="form.abierto"
      :titulo="form.id ? `Editar ${NOMBRE_TIPO[form.ficha.tipo]}` : `Nuevo ${NOMBRE_TIPO[form.ficha.tipo]}`"
      :descripcion="form.id ? 'Los cambios se guardan recién al confirmar.' : 'Quedará habilitado para entradas, préstamos, reservas y el portal.'"
      ancho="lg"
      destacado
      :bloqueado="form.guardando"
      @cerrar="form.abierto = false"
    >
      <div class="space-y-3">
        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Identidad') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Identidad</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
        <div>
          <p class="mb-1 block text-xs font-medium text-gray-600">Documento de identidad</p>
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
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="est-rut" class="mb-1 block text-xs font-medium text-gray-600">
              {{ form.ficha.tipo_documento === 'rut' ? 'RUT' : form.ficha.tipo_documento === 'pasaporte' ? 'N.º de pasaporte' : 'N.º de documento' }}
            </label>
            <input
              id="est-rut"
              :value="form.ficha.rut"
              :placeholder="form.ficha.tipo_documento === 'rut' ? '12.345.678-5' : form.ficha.tipo_documento === 'pasaporte' ? 'Ej: FT482913' : 'Ej: 38456123'"
              :maxlength="form.ficha.tipo_documento === 'rut' ? 12 : 20"
              autocomplete="off"
              :class="clasesInput('rut')"
              @input="form.ficha.rut = formatearInputIdentificador($event, form.ficha.tipo_documento); limpiarError('rut')"
            />
            <p v-if="form.errores.rut" class="mt-1 text-xs text-red-600">{{ form.errores.rut }}</p>
          </div>
          <div v-if="form.ficha.tipo_documento !== 'rut'">
            <label for="est-pais" class="mb-1 block text-xs font-medium text-gray-600">País (opcional)</label>
            <input id="est-pais" v-model="form.ficha.pais" maxlength="100" placeholder="Ej: Argentina" :class="clasesInput('pais')" />
          </div>
        </div>

        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Datos personales') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Datos personales</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="est-nombre" class="mb-1 block text-xs font-medium text-gray-600">Nombre</label>
            <input id="est-nombre" v-model="form.ficha.nombre" maxlength="255" :class="clasesInput('nombre')" @input="limpiarError('nombre')" />
            <p v-if="form.errores.nombre" class="mt-1 text-xs text-red-600">{{ form.errores.nombre }}</p>
          </div>
          <div>
            <label for="est-apellido" class="mb-1 block text-xs font-medium text-gray-600">Apellido</label>
            <input id="est-apellido" v-model="form.ficha.apellido" maxlength="255" :class="clasesInput('apellido')" @input="limpiarError('apellido')" />
            <p v-if="form.errores.apellido" class="mt-1 text-xs text-red-600">{{ form.errores.apellido }}</p>
          </div>
          <div>
            <label for="est-sexo" class="mb-1 block text-xs font-medium text-gray-600">Sexo (opcional)</label>
            <select id="est-sexo" v-model="form.ficha.sexo" :class="clasesInput('sexo')">
              <option value="">Sin informar</option>
              <option v-for="s in opciones.sexos" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div v-if="form.id">
            <label for="est-tipo" class="mb-1 block text-xs font-medium text-gray-600">Tipo</label>
            <select id="est-tipo" v-model="form.ficha.tipo" :class="clasesInput('tipo')">
              <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}</option>
            </select>
          </div>
        </div>

        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Contacto') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Contacto</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
        <div>
          <label for="est-email" class="mb-1 block text-xs font-medium text-gray-600">Correo (opcional)</label>
          <input id="est-email" v-model="form.ficha.email" type="email" maxlength="255" placeholder="nombre.apellido@umag.cl" :class="clasesInput('email')" @input="limpiarError('email')" />
          <p v-if="form.errores.email" class="mt-1 text-xs text-red-600">{{ form.errores.email }}</p>
        </div>
        <div>
          <p class="mb-1 block text-xs font-medium text-gray-600">Correos adicionales (opcional)</p>
          <ul v-if="form.ficha.correos_adicionales.length" class="mb-2 flex flex-wrap gap-1.5">
            <li v-for="c in form.ficha.correos_adicionales" :key="c" class="inline-flex items-center gap-1.5 rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs text-indigo-900">
              {{ c }}
              <button type="button" class="text-indigo-500 hover:text-red-600" :aria-label="`Quitar ${c}`" @click="quitarCorreo(c)">×</button>
            </li>
          </ul>
          <div class="flex gap-2">
            <input
              v-model="nuevoCorreo"
              type="email"
              maxlength="255"
              placeholder="otro.correo@gmail.com"
              aria-label="Correo adicional"
              :class="clasesInput('correos_adicionales')"
              @keydown.enter.prevent="agregarCorreo"
              @input="limpiarError('correos_adicionales')"
            />
            <button type="button" class="shrink-0 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-gray-50" @click="agregarCorreo">Agregar</button>
          </div>
          <p v-if="form.errores.correos_adicionales" class="mt-1 text-xs text-red-600">{{ form.errores.correos_adicionales }}</p>
          <p v-else class="mt-1 text-[11px] text-gray-500">Sirven para reconocerla al entrar con Google o el directorio. Las notificaciones van al correo principal.</p>
        </div>

        <template v-if="pideCarrera">
        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Académico') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Académico</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
          <div>
            <label for="est-carrera" class="mb-1 block text-xs font-medium text-gray-600">Carrera (opcional)</label>
            <input id="est-carrera" v-model="form.ficha.carrera" list="est-carreras" maxlength="255" placeholder="Elige una o escribe una nueva" :class="clasesInput('carrera')" />
            <datalist id="est-carreras">
              <option v-for="c in opciones.carreras" :key="c" :value="c" />
            </datalist>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label for="est-anio" class="mb-1 block text-xs font-medium text-gray-600">Año de ingreso (opcional)</label>
              <input id="est-anio" v-model="form.ficha.anio_ingreso" inputmode="numeric" maxlength="4" placeholder="2026" :class="clasesInput('anio_ingreso')" @input="limpiarError('anio_ingreso')" />
              <p v-if="form.errores.anio_ingreso" class="mt-1 text-xs text-red-600">{{ form.errores.anio_ingreso }}</p>
            </div>
          </div>
        </template>

        <template v-if="pideUnidad">
        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Trabajo') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Trabajo</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label for="est-unidad" class="mb-1 block text-xs font-medium text-gray-600">Unidad o departamento (opcional)</label>
              <input id="est-unidad" v-model="form.ficha.unidad" maxlength="255" placeholder="Ej: Departamento de Ciencias" :class="clasesInput('unidad')" />
            </div>
            <div>
              <label for="est-cargo" class="mb-1 block text-xs font-medium text-gray-600">Cargo (opcional)</label>
              <input id="est-cargo" v-model="form.ficha.cargo" maxlength="255" placeholder="Ej: Profesor adjunto" :class="clasesInput('cargo')" />
            </div>
          </div>
        </template>

        <div class="flex items-center gap-2.5">
          <span class="flex h-5 w-5 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ numero('Cuenta') }}</span>
          <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-900/70">Cuenta</span>
          <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div :class="form.id ? '' : 'sm:col-span-2'">
            <label for="est-password" class="mb-1 block text-xs font-medium text-gray-600">Contraseña del portal (opcional)</label>
            <input
              id="est-password"
              v-model="form.ficha.password"
              autocomplete="new-password"
              :placeholder="form.id ? 'Déjala en blanco para no cambiarla' : 'Sin ella, entra con el login institucional'"
              :class="clasesInput('password')"
              @input="limpiarError('password')"
            />
            <p v-if="form.errores.password" class="mt-1 text-xs text-red-600">{{ form.errores.password }}</p>
          </div>
          <div v-if="form.id">
            <label for="usuario-vigente" class="mb-1 block text-xs font-medium text-gray-600">Cuenta vigente hasta</label>
            <input id="usuario-vigente" v-model="form.ficha.vigente_hasta" type="date" :class="clasesInput('vigente_hasta')" @input="limpiarError('vigente_hasta')" />
            <p v-if="form.errores.vigente_hasta" class="mt-1 text-xs text-red-600">{{ form.errores.vigente_hasta }}</p>
            <p v-else class="mt-1 text-[11px] text-gray-500">Último día en que la cuenta sirve. Pasada esa fecha queda vencida hasta que se renueve.</p>
          </div>
        </div>
        <div v-if="form.id">
          <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 px-3 py-2.5 text-sm" :class="[form.ficha.activo ? 'border-indigo-300 bg-indigo-50/40' : '', bloqueaDesactivar ? 'opacity-60' : '']">
            <input v-model="form.ficha.activo" type="checkbox" :disabled="bloqueaDesactivar" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
            <span>
              <span class="block font-semibold text-gray-900">Activo</span>
              <span class="block text-xs text-gray-500">Si se desactiva, no puede registrar entradas, pedir préstamos ni entrar al portal.</span>
            </span>
          </label>
          <p v-if="bloqueaDesactivar" class="mt-1 text-xs text-gray-500">Solo un Administrador/a puede desactivar a un usuario.</p>
        </div>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-100 transition-colors font-semibold text-sm"
          :disabled="form.guardando"
          @click="form.abierto = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-semibold text-sm shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] disabled:opacity-60"
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

    <CargaMasivaEstudiantes v-model:abierto="cargaAbierta" @cargado="cargar(); cargarOpciones()" />
  </section>
</template>
