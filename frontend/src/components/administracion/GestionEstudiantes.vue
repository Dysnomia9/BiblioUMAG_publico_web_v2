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
  tipo: Usuario['tipo']
  carrera: string
  anio_ingreso: string
  sexo: string
  activo: boolean
  password: string
}

const fichaVacia = (): Ficha => ({
  tipo_documento: 'rut', rut: '', pais: '', nombre: '', apellido: '', email: '', tipo: 'estudiante',
  carrera: '', anio_ingreso: String(new Date().getFullYear()), sexo: '', activo: true, password: '',
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
    tipo: u.tipo,
    carrera: u.carrera ?? '',
    anio_ingreso: u.anio_ingreso ? String(u.anio_ingreso) : '',
    sexo: u.sexo ?? '',
    activo: u.activo,
    password: '',
  }
}

function abrirNuevo() {
  Object.assign(form, { abierto: true, id: null, ficha: fichaVacia(), original: null, errores: {}, confirmando: false })
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
  tipo: 'Tipo', carrera: 'Carrera', anio_ingreso: 'Año de ingreso', sexo: 'Sexo', activo: 'Estado', password: 'Contraseña del portal',
}

function mostrar(campo: keyof Ficha, f: Ficha): string {
  if (campo === 'tipo_documento') return TIPOS_DOCUMENTO[f.tipo_documento]
  if (campo === 'tipo') return TIPO_LABEL[f.tipo]
  if (campo === 'activo') return f.activo ? 'Activo' : 'Inactivo'
  if (campo === 'password') return f.password ? 'nueva (asignada)' : '—'
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
    tipo: f.tipo,
    carrera: f.carrera.trim() || null,
    anio_ingreso: f.anio_ingreso ? Number(f.anio_ingreso) : null,
    sexo: f.sexo || null,
    activo: f.activo,
    password: f.password || undefined,
  }
  form.guardando = true
  try {
    if (form.id) {
      await api.put(`/usuarios/${form.id}`, datos)
      toast.success(`Datos de ${datos.nombre} ${datos.apellido} actualizados`)
    } else {
      await api.post('/usuarios', datos)
      toast.success(`${datos.nombre} ${datos.apellido} quedó registrado/a`)
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
  <div class="bg-white rounded-xl shadow-md p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-4">
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Estudiantes y usuarios</h3>
        <p class="text-xs text-gray-500 max-w-xl">
          Registra estudiantes (con RUT o, si son extranjeros, con pasaporte o DNI), corrige sus datos o cárgalos en forma masiva
          desde una planilla Excel o CSV. Todo cambio pide confirmación y queda en el Registro absoluto.
        </p>
      </div>
      <div class="flex shrink-0 gap-2">
        <button
          type="button"
          class="px-4 py-2.5 border border-indigo-200 text-indigo-700 rounded-lg hover:bg-indigo-50 transition-colors font-medium text-sm"
          @click="cargaAbierta = true"
        >
          Carga masiva (Excel/CSV)
        </button>
        <button type="button" class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="abrirNuevo">
          + Nuevo estudiante
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-4">
      <input
        v-model="filtros.q"
        type="search"
        placeholder="Buscar por nombre, RUT, pasaporte o carrera"
        aria-label="Buscar estudiantes"
        class="sm:col-span-2 w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
      />
      <select v-model="filtros.tipo" aria-label="Filtrar por tipo" class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
        <option value="">Todos los tipos</option>
        <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}s</option>
      </select>
      <select v-model="filtros.carrera" aria-label="Filtrar por carrera" class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
        <option value="">Todas las carreras</option>
        <option v-for="c in opciones.carreras" :key="c" :value="c">{{ c }}</option>
      </select>
    </div>

    <p class="text-xs text-gray-500 mb-2">{{ cargando ? 'Cargando…' : `${total.toLocaleString('es-CL')} ${total === 1 ? 'persona' : 'personas'}` }}</p>

    <div class="rounded-lg border border-gray-200 overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
            <th class="px-4 py-2.5">Documento</th>
            <th class="px-4 py-2.5">Nombre</th>
            <th class="px-4 py-2.5 hidden md:table-cell">Carrera</th>
            <th class="px-4 py-2.5 hidden sm:table-cell">Estado</th>
            <th class="px-4 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="u in usuarios" :key="u.id" class="hover:bg-indigo-50/40">
            <td class="px-4 py-2.5 font-mono text-xs text-gray-700 whitespace-nowrap">
              {{ describirDocumento(u.rut, u.tipo_documento, u.pais) }}
            </td>
            <td class="px-4 py-2.5">
              <p class="font-medium text-gray-900">{{ u.nombre }} {{ u.apellido }}</p>
              <p class="text-xs text-gray-500">{{ TIPO_LABEL[u.tipo] }}{{ u.email ? ` · ${u.email}` : '' }}</p>
            </td>
            <td class="px-4 py-2.5 text-gray-600 hidden md:table-cell">
              {{ u.carrera ?? '—' }}<span v-if="u.anio_ingreso" class="text-xs text-gray-400"> · {{ u.anio_ingreso }}</span>
            </td>
            <td class="px-4 py-2.5 hidden sm:table-cell">
              <span class="text-xs font-medium px-2.5 py-1 rounded-full" :class="u.activo ? 'bg-emerald-100 text-emerald-700' : u.inactividad === 'vencida' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-700'">
                {{ u.activo ? 'Activo' : u.inactividad === 'vencida' ? 'Vencida' : 'Inactivo' }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <button type="button" class="font-medium text-indigo-700 hover:text-indigo-800" @click="abrirEdicion(u)">Editar</button>
            </td>
          </tr>
          <tr v-if="!cargando && !usuarios.length">
            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Sin personas que coincidan con los filtros.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Paginacion v-model="pagina" class="mt-3" :total="total" :por-pagina="porPagina" singular="persona" plural="personas" />

    <Modal
      :abierto="form.abierto"
      :titulo="form.id ? 'Editar estudiante' : 'Nuevo estudiante'"
      :descripcion="form.id ? 'Los cambios se guardan recién al confirmar.' : 'Quedará habilitado para entradas, préstamos, reservas y el portal.'"
      ancho="lg"
      pie-fijo
      :bloqueado="form.guardando"
      @cerrar="form.abierto = false"
    >
      <div class="space-y-4">
        <div>
          <p class="block text-sm font-medium text-gray-700 mb-1">Documento de identidad</p>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-1 rounded-lg bg-gray-100 p-1" role="radiogroup" aria-label="Tipo de documento">
            <button
              v-for="(nombre, clave) in TIPOS_DOCUMENTO"
              :key="clave"
              type="button"
              role="radio"
              :aria-checked="form.ficha.tipo_documento === clave"
              class="rounded-md px-2 py-1.5 text-xs font-semibold transition-colors"
              :class="form.ficha.tipo_documento === clave ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
              @click="elegirTipoDocumento(clave)"
            >
              {{ clave === 'dni' ? 'DNI / cédula' : nombre }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label for="est-rut" class="block text-xs font-medium text-gray-600 mb-1">
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
            <p v-if="form.errores.rut" class="text-xs text-red-600 mt-1">{{ form.errores.rut }}</p>
          </div>
          <div v-if="form.ficha.tipo_documento !== 'rut'">
            <label for="est-pais" class="block text-xs font-medium text-gray-600 mb-1">País (opcional)</label>
            <input id="est-pais" v-model="form.ficha.pais" maxlength="100" placeholder="Ej: Argentina" :class="clasesInput('pais')" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label for="est-nombre" class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
            <input id="est-nombre" v-model="form.ficha.nombre" maxlength="255" :class="clasesInput('nombre')" @input="limpiarError('nombre')" />
            <p v-if="form.errores.nombre" class="text-xs text-red-600 mt-1">{{ form.errores.nombre }}</p>
          </div>
          <div>
            <label for="est-apellido" class="block text-xs font-medium text-gray-600 mb-1">Apellido</label>
            <input id="est-apellido" v-model="form.ficha.apellido" maxlength="255" :class="clasesInput('apellido')" @input="limpiarError('apellido')" />
            <p v-if="form.errores.apellido" class="text-xs text-red-600 mt-1">{{ form.errores.apellido }}</p>
          </div>
          <div>
            <label for="est-email" class="block text-xs font-medium text-gray-600 mb-1">Correo (opcional)</label>
            <input id="est-email" v-model="form.ficha.email" type="email" maxlength="255" placeholder="nombre.apellido@umag.cl" :class="clasesInput('email')" @input="limpiarError('email')" />
            <p v-if="form.errores.email" class="text-xs text-red-600 mt-1">{{ form.errores.email }}</p>
          </div>
          <div>
            <label for="est-tipo" class="block text-xs font-medium text-gray-600 mb-1">Tipo</label>
            <select id="est-tipo" v-model="form.ficha.tipo" :class="clasesInput('tipo')">
              <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label for="est-carrera" class="block text-xs font-medium text-gray-600 mb-1">Carrera (opcional)</label>
            <input id="est-carrera" v-model="form.ficha.carrera" list="est-carreras" maxlength="255" placeholder="Elige una o escribe una nueva" :class="clasesInput('carrera')" />
            <datalist id="est-carreras">
              <option v-for="c in opciones.carreras" :key="c" :value="c" />
            </datalist>
          </div>
          <div>
            <label for="est-anio" class="block text-xs font-medium text-gray-600 mb-1">Año de ingreso (opcional)</label>
            <input id="est-anio" v-model="form.ficha.anio_ingreso" inputmode="numeric" maxlength="4" placeholder="2026" :class="clasesInput('anio_ingreso')" @input="limpiarError('anio_ingreso')" />
            <p v-if="form.errores.anio_ingreso" class="text-xs text-red-600 mt-1">{{ form.errores.anio_ingreso }}</p>
          </div>
          <div>
            <label for="est-sexo" class="block text-xs font-medium text-gray-600 mb-1">Sexo (opcional)</label>
            <select id="est-sexo" v-model="form.ficha.sexo" :class="clasesInput('sexo')">
              <option value="">Sin informar</option>
              <option v-for="s in opciones.sexos" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label for="est-password" class="block text-xs font-medium text-gray-600 mb-1">
              Contraseña del portal (opcional)
            </label>
            <input
              id="est-password"
              v-model="form.ficha.password"
              autocomplete="new-password"
              :placeholder="form.id ? 'Déjala en blanco para no cambiarla' : 'Sin ella, entra con el login institucional'"
              :class="clasesInput('password')"
              @input="limpiarError('password')"
            />
            <p v-if="form.errores.password" class="text-xs text-red-600 mt-1">{{ form.errores.password }}</p>
          </div>
          <div v-if="form.id" class="sm:col-span-2">
            <label class="flex items-center gap-2 text-sm text-gray-700" :class="{ 'opacity-60': bloqueaDesactivar }">
              <input v-model="form.ficha.activo" type="checkbox" :disabled="bloqueaDesactivar" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
              Activo (si se desactiva, no puede registrar entradas, pedir préstamos ni entrar al portal)
            </label>
            <p v-if="bloqueaDesactivar" class="mt-1 text-xs text-gray-500">Solo un Administrador/a puede desactivar a un usuario.</p>
          </div>
        </div>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          :disabled="form.guardando"
          @click="form.abierto = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
          :disabled="form.guardando"
          @click="pedirConfirmacion"
        >
          {{ form.id ? 'Revisar cambios' : 'Revisar y crear' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="form.confirmando"
      :titulo="form.id ? '¿Guardar estos cambios?' : '¿Crear este estudiante?'"
      :confirmar="form.id ? 'Sí, guardar cambios' : 'Sí, crear'"
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
          <template v-if="form.ficha.carrera"><dt>Carrera</dt><dd class="text-gray-900">{{ form.ficha.carrera }}<template v-if="form.ficha.anio_ingreso"> · {{ form.ficha.anio_ingreso }}</template></dd></template>
          <template v-if="form.ficha.email"><dt>Correo</dt><dd class="text-gray-900">{{ form.ficha.email }}</dd></template>
          <dt>Portal</dt>
          <dd class="text-gray-900">{{ form.ficha.password ? 'con contraseña asignada' : 'sin contraseña (login institucional)' }}</dd>
        </dl>
      </template>
    </DialogoConfirmacion>

    <CargaMasivaEstudiantes v-model:abierto="cargaAbierta" @cargado="cargar(); cargarOpciones()" />
  </div>
</template>
