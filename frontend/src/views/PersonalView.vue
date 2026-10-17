<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoPagina from '@/components/ui/EncabezadoPagina.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { sinTildes } from '@/utils/texto'
import type { CuentaPersonal, GrupoPermisos, Rol } from '@/types'

const auth = useAuthStore()
const toast = useToast()

const pestana = ref<'cuentas' | 'roles'>('cuentas')
const apiError = ref(false)
const cargando = ref(true)

const cuentas = ref<CuentaPersonal[]>([])
const roles = ref<Rol[]>([])
const catalogo = ref<GrupoPermisos[]>([])
const busqueda = ref('')

const cuentasFiltradas = computed(() => {
  const q = sinTildes(busqueda.value.trim())
  if (!q) return cuentas.value
  return cuentas.value.filter((c) => sinTildes(`${c.nombre} ${c.email} ${c.rol_nombre ?? ''}`).includes(q))
})

const totalPermisos = computed(() => catalogo.value.reduce((s, g) => s + g.permisos.length, 0))

async function cargar() {
  try {
    const [c, r] = await Promise.all([
      api.get<CuentaPersonal[]>('/personal'),
      api.get<{ roles: Rol[]; catalogo: GrupoPermisos[] }>('/roles'),
    ])
    cuentas.value = c.data
    roles.value = r.data.roles
    catalogo.value = r.data.catalogo
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

function rolDe(clave: string) {
  return roles.value.find((r) => r.clave === clave)
}

function formatAcceso(iso: string | null) {
  if (!iso) return 'Nunca'
  const fecha = new Date(iso)
  const dias = Math.floor((Date.now() - fecha.getTime()) / 86_400_000)
  if (dias === 0) return `Hoy ${fecha.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}`
  if (dias === 1) return 'Ayer'
  if (dias < 30) return `Hace ${dias} días`
  return fecha.toLocaleDateString('es-CL')
}

function generarClave() {
  const alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'
  const valores = crypto.getRandomValues(new Uint32Array(10))
  return Array.from(valores, (v) => alfabeto[v % alfabeto.length]).join('')
}

async function copiar(texto: string) {
  try {
    await navigator.clipboard.writeText(texto)
    toast.info('Contraseña copiada')
  } catch {
    toast.error('No se pudo copiar; selecciónala y cópiala a mano')
  }
}

type Cambio = { campo: string; antes: string; despues: string }

function resumirLista(lista: string[]) {
  return lista.length <= 5 ? lista.join(', ') : `${lista.slice(0, 5).join(', ')} y ${lista.length - 5} más`
}

// ---------- Cuentas ----------
// Guardar pide confirmación: el formulario queda debajo y el diálogo resume lo que cambia.
const formCuenta = reactive({ abierto: false, confirmando: false, guardando: false, id: null as number | null, nombre: '', email: '', password: '', rol: 'asistente' })

function nuevaCuenta() {
  Object.assign(formCuenta, { abierto: true, confirmando: false, id: null, nombre: '', email: '', password: generarClave(), rol: 'asistente' })
}

function editarCuenta(c: CuentaPersonal) {
  Object.assign(formCuenta, { abierto: true, confirmando: false, id: c.id, nombre: c.nombre, email: c.email, password: '', rol: c.rol })
}

const rolElegido = computed(() => rolDe(formCuenta.rol))
const cuentaOriginal = computed(() => cuentas.value.find((c) => c.id === formCuenta.id) ?? null)

const cambiosCuenta = computed(() => {
  const o = cuentaOriginal.value
  if (!o) return []
  const cambios: Cambio[] = []
  if (o.nombre !== formCuenta.nombre.trim()) cambios.push({ campo: 'Nombre', antes: o.nombre, despues: formCuenta.nombre.trim() })
  if (o.email !== formCuenta.email.trim()) cambios.push({ campo: 'Correo', antes: o.email, despues: formCuenta.email.trim() })
  if (o.rol !== formCuenta.rol) cambios.push({ campo: 'Rol', antes: rolDe(o.rol)?.nombre ?? o.rol, despues: rolElegido.value?.nombre ?? formCuenta.rol })
  return cambios
})
const cambiaRolCuenta = computed(() => !formCuenta.id || cambiosCuenta.value.some((c) => c.campo === 'Rol'))

function pedirConfirmacionCuenta() {
  if (!formCuenta.nombre.trim() || !formCuenta.email.trim()) {
    toast.error('Completa el nombre y el correo')
    return
  }
  if (formCuenta.id && !cambiosCuenta.value.length) {
    formCuenta.abierto = false
    toast.info('No hubo cambios que guardar')
    return
  }
  formCuenta.confirmando = true
}

async function guardarCuenta() {
  formCuenta.guardando = true
  try {
    if (formCuenta.id) {
      await api.put(`/personal/${formCuenta.id}`, { nombre: formCuenta.nombre, email: formCuenta.email, rol: formCuenta.rol })
      toast.success('Cuenta actualizada')
    } else {
      await api.post('/personal', { nombre: formCuenta.nombre, email: formCuenta.email, password: formCuenta.password, rol: formCuenta.rol })
      toast.success('Cuenta creada. Entrégale el correo y la contraseña inicial.')
    }
    Object.assign(formCuenta, { abierto: false, confirmando: false })
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la cuenta'))
    formCuenta.confirmando = false
  } finally {
    formCuenta.guardando = false
  }
}

const formClave = reactive({ abierto: false, confirmando: false, guardando: false, cuenta: null as CuentaPersonal | null, password: '' })

function restablecerClave(c: CuentaPersonal) {
  // Parte vacía: la contraseña actual no se puede mostrar (se guarda cifrada) y una sugerida
  // al azar en cada apertura parecía «la contraseña» cambiando sola.
  Object.assign(formClave, { abierto: true, confirmando: false, cuenta: c, password: '' })
}

async function guardarClave() {
  if (!formClave.cuenta) return
  formClave.guardando = true
  try {
    await api.patch(`/personal/${formClave.cuenta.id}/password`, { password: formClave.password })
    toast.success('Contraseña restablecida. Sus sesiones abiertas se cerraron.')
    Object.assign(formClave, { abierto: false, confirmando: false })
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo restablecer la contraseña'))
    formClave.confirmando = false
  } finally {
    formClave.guardando = false
  }
}

const cuentaACambiar = ref<CuentaPersonal | null>(null)
const cambiandoEstado = ref(false)

async function confirmarCambioEstado() {
  if (!cuentaACambiar.value) return
  cambiandoEstado.value = true
  try {
    await api.patch(`/personal/${cuentaACambiar.value.id}/activo`, { activo: !cuentaACambiar.value.activo })
    toast.success(cuentaACambiar.value.activo ? 'Cuenta desactivada y sesión cerrada' : 'Cuenta reactivada')
    cuentaACambiar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el estado de la cuenta'))
  } finally {
    cambiandoEstado.value = false
  }
}

// Eliminar (solo cuentas ya desactivadas): la cuenta desaparece, su historial se conserva.
const cuentaAEliminar = ref<CuentaPersonal | null>(null)
const textoConfirmacion = ref('')
const eliminandoCuenta = ref(false)
const confirmacionEscrita = computed(() => textoConfirmacion.value.trim().toLowerCase() === 'confirmar')

function pedirEliminarCuenta(c: CuentaPersonal) {
  textoConfirmacion.value = ''
  cuentaAEliminar.value = c
}

async function confirmarEliminarCuenta() {
  if (!cuentaAEliminar.value || !confirmacionEscrita.value) return
  eliminandoCuenta.value = true
  try {
    await api.delete(`/personal/${cuentaAEliminar.value.id}`, { data: { confirmacion: textoConfirmacion.value } })
    toast.success(`Cuenta de ${cuentaAEliminar.value.nombre} eliminada. Su historial se conserva.`)
    cuentaAEliminar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar la cuenta'))
  } finally {
    eliminandoCuenta.value = false
  }
}

// ---------- Roles ----------
const formRol = reactive({
  abierto: false,
  confirmando: false,
  guardando: false,
  id: null as number | null,
  accesoTotal: false,
  nombre: '',
  descripcion: '',
  nivel: 3,
  permisos: new Set<string>(),
  copiarDe: '',
})

function nuevoRol() {
  Object.assign(formRol, { abierto: true, confirmando: false, id: null, accesoTotal: false, nombre: '', descripcion: '', nivel: 3, permisos: new Set<string>(), copiarDe: '' })
}

function editarRol(r: Rol) {
  Object.assign(formRol, {
    abierto: true,
    confirmando: false,
    id: r.id,
    accesoTotal: r.acceso_total,
    nombre: r.nombre,
    descripcion: r.descripcion ?? '',
    nivel: r.nivel,
    permisos: new Set(r.permisos),
    copiarDe: '',
  })
}

function esSoloAccesoTotal(clave: string) {
  return catalogo.value.some((g) => g.permisos.some((p) => p.clave === clave && p.solo_acceso_total))
}

function copiarPermisosDe(clave: string) {
  const origen = rolDe(clave)
  if (origen) formRol.permisos = new Set(origen.permisos.filter((p) => !esSoloAccesoTotal(p)))
}

function alternarPermiso(clave: string) {
  const nuevo = new Set(formRol.permisos)
  if (nuevo.has(clave)) nuevo.delete(clave)
  else nuevo.add(clave)
  formRol.permisos = nuevo
}

const rolOriginal = computed(() => roles.value.find((r) => r.id === formRol.id) ?? null)

function nombrePermiso(clave: string) {
  for (const g of catalogo.value) {
    const p = g.permisos.find((x) => x.clave === clave)
    if (p) return p.nombre
  }
  return clave
}

const cambiosRol = computed(() => {
  const o = rolOriginal.value
  const datos: Cambio[] = []
  if (o) {
    if (o.nombre !== formRol.nombre.trim()) datos.push({ campo: 'Nombre', antes: o.nombre, despues: formRol.nombre.trim() })
    if ((o.descripcion ?? '') !== formRol.descripcion.trim()) {
      datos.push({ campo: 'Descripción', antes: o.descripcion || '(sin descripción)', despues: formRol.descripcion.trim() || '(sin descripción)' })
    }
    if (!formRol.accesoTotal && o.nivel !== formRol.nivel) datos.push({ campo: 'Nivel', antes: String(o.nivel), despues: String(formRol.nivel) })
  }
  const antes = new Set(o?.permisos ?? [])
  const agregados = formRol.accesoTotal ? [] : [...formRol.permisos].filter((p) => !antes.has(p))
  const quitados = formRol.accesoTotal || !o ? [] : o.permisos.filter((p) => !formRol.permisos.has(p))
  return { datos, agregados: agregados.map(nombrePermiso), quitados: quitados.map(nombrePermiso) }
})

function pedirConfirmacionRol() {
  if (!formRol.nombre.trim()) {
    toast.error('Ponle un nombre al rol')
    return
  }
  const c = cambiosRol.value
  if (formRol.id && !c.datos.length && !c.agregados.length && !c.quitados.length) {
    formRol.abierto = false
    toast.info('No hubo cambios que guardar')
    return
  }
  formRol.confirmando = true
}

async function guardarRol() {
  formRol.guardando = true
  const datos = {
    nombre: formRol.nombre.trim(),
    descripcion: formRol.descripcion.trim() || null,
    nivel: formRol.accesoTotal ? undefined : formRol.nivel,
    permisos: formRol.accesoTotal ? undefined : [...formRol.permisos],
  }
  try {
    if (formRol.id) {
      await api.put(`/roles/${formRol.id}`, datos)
      toast.success('Rol actualizado. Los cambios aplican desde la próxima acción de cada persona.')
    } else {
      await api.post('/roles', datos)
      toast.success('Rol creado. Ya puedes asignarlo a una cuenta.')
    }
    Object.assign(formRol, { abierto: false, confirmando: false })
    await cargar()
    // Si se editó el rol de la propia cuenta, el menú se actualiza de inmediato.
    const { data } = await api.get('/auth/me')
    auth.staff = data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar el rol'))
    formRol.confirmando = false
  } finally {
    formRol.guardando = false
  }
}

const rolAEliminar = ref<Rol | null>(null)
const eliminandoRol = ref(false)

async function confirmarEliminarRol() {
  if (!rolAEliminar.value) return
  eliminandoRol.value = true
  try {
    await api.delete(`/roles/${rolAEliminar.value.id}`)
    toast.success('Rol eliminado')
    rolAEliminar.value = null
    formRol.abierto = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar el rol'))
  } finally {
    eliminandoRol.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <EncabezadoPagina titulo="Personal y Roles" descripcion="Cuentas del personal de la biblioteca y qué puede hacer cada rol">
        <template #acciones>
          <button
            @click="pestana === 'cuentas' ? nuevaCuenta() : nuevoRol()"
            class="px-4 py-2 bg-white text-indigo-700 rounded-lg hover:bg-indigo-50 transition-colors font-medium text-sm shadow-sm"
          >
            {{ pestana === 'cuentas' ? '+ Nueva cuenta' : '+ Nuevo rol' }}
          </button>
        </template>
      </EncabezadoPagina>

      <GuiaUso
        :pasos="[
          'Cada persona que atiende debe tener <strong>su propia cuenta</strong>: así queda registrado quién hizo cada préstamo, devolución o cambio.',
          'El <strong>rol</strong> define qué módulos y botones ve. Ej.: «Atención de público» registra entradas, préstamos y salas, pero no cataloga ni cambia la configuración.',
          'Si alguien deja de trabajar en la biblioteca, <strong>desactive</strong> su cuenta: su sesión se cierra al instante y su historial se conserva. Si no va a volver, después puede <strong>eliminarla</strong> (escribiendo «confirmar»): deja de aparecer en la lista, pero lo que registró sigue con su nombre.',
          'En «Roles y permisos» puede ajustar qué incluye cada rol o <strong>crear uno nuevo</strong> (ej.: «Ayudante de sala» que solo registra entradas).',
        ]"
        consejo="Solo el rol con acceso total (Administrador/a) puede gestionar cuentas y roles, para que nadie pueda darse más permisos a sí mismo."
      />

      <ApiErrorBanner v-if="apiError" />

      <div class="flex flex-col sm:flex-row gap-1 mb-4 bg-white rounded-xl shadow-sm p-1">
        <button
          @click="pestana = 'cuentas'"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === 'cuentas' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
        >
          Cuentas del personal ({{ cuentas.length }})
        </button>
        <button
          @click="pestana = 'roles'"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === 'roles' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
        >
          Roles y permisos ({{ roles.length }})
        </button>
      </div>

      <!-- ============ CUENTAS ============ -->
      <template v-if="pestana === 'cuentas'">
        <div class="bg-white rounded-xl shadow-md p-4 mb-4">
          <input
            v-model="busqueda"
            type="text"
            placeholder="Buscar por nombre, correo o rol"
            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />
        </div>

        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nombre</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Rol</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Último acceso</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="(c, idx) in cuentasFiltradas" :key="c.id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                  <td class="px-5 py-3 text-sm">
                    <p class="font-medium text-gray-900">
                      {{ c.nombre }}
                      <span v-if="c.id === auth.staff?.id" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full font-semibold bg-amber-100 text-amber-800">Tú</span>
                    </p>
                    <p class="text-xs text-gray-500">{{ c.email }}</p>
                  </td>
                  <td class="px-5 py-3 text-sm">
                    <span
                      class="text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap"
                      :class="rolDe(c.rol)?.acceso_total ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-700'"
                    >
                      {{ c.rol_nombre ?? c.rol }}
                    </span>
                  </td>
                  <td class="px-5 py-3 text-sm">
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="c.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'">
                      {{ c.activo ? 'Activa' : 'Desactivada' }}
                    </span>
                  </td>
                  <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">{{ formatAcceso(c.ultimo_acceso) }}</td>
                  <td class="px-5 py-3 text-sm whitespace-nowrap">
                    <button @click="editarCuenta(c)" class="text-indigo-700 hover:text-indigo-800 font-medium mr-3">Editar</button>
                    <button @click="restablecerClave(c)" class="text-indigo-700 hover:text-indigo-800 font-medium mr-3">Restablecer contraseña</button>
                    <button
                      v-if="c.id !== auth.staff?.id"
                      @click="cuentaACambiar = c"
                      class="font-medium"
                      :class="c.activo ? 'text-red-600 hover:text-red-700' : 'text-emerald-700 hover:text-emerald-800'"
                    >
                      {{ c.activo ? 'Desactivar' : 'Reactivar' }}
                    </button>
                    <button
                      v-if="c.id !== auth.staff?.id && !c.activo"
                      @click="pedirEliminarCuenta(c)"
                      class="ml-3 font-medium text-red-600 hover:text-red-700"
                    >
                      Eliminar
                    </button>
                  </td>
                </tr>
                <tr v-if="!cargando && !cuentasFiltradas.length">
                  <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">Sin cuentas que coincidan con la búsqueda.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ============ ROLES ============ -->
      <template v-else>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
          <div v-for="r in roles" :key="r.id" class="bg-white rounded-xl shadow-md border-t-4 p-5 flex flex-col" :class="r.acceso_total ? 'border-t-amber-400' : 'border-t-indigo-400'">
            <div class="flex items-center justify-between gap-2 mb-2">
              <span class="text-xs px-2 py-0.5 rounded-full font-semibold" :class="r.acceso_total ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-700'">Nivel {{ r.nivel }}</span>
              <span class="text-xs text-gray-500">{{ r.staff_count }} {{ r.staff_count === 1 ? 'cuenta' : 'cuentas' }}</span>
            </div>
            <h3 class="font-serif font-semibold text-gray-900 text-lg">{{ r.nombre }}</h3>
            <p class="text-sm text-gray-500 mt-1 flex-1">{{ r.descripcion ?? 'Sin descripción.' }}</p>
            <p class="text-xs text-gray-500 mt-3">{{ r.acceso_total ? 'Acceso total a todo el sistema' : `${r.permisos.length} de ${totalPermisos} permisos` }}</p>
            <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1.5">
              <div class="h-full rounded-full" :class="r.acceso_total ? 'bg-amber-400' : 'bg-indigo-500'" :style="{ width: `${Math.round((r.permisos.length / Math.max(1, totalPermisos)) * 100)}%` }" />
            </div>
            <button @click="editarRol(r)" class="mt-4 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">
              {{ r.acceso_total ? 'Cambiar nombre' : 'Editar permisos' }}
            </button>
          </div>
        </div>

        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-serif font-semibold text-gray-900">¿Qué puede hacer cada rol?</h3>
            <p class="text-xs text-gray-500 mt-0.5">Comparación rápida de la jerarquía. Para cambiar un rol, usa «Editar permisos» en su tarjeta.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem]">
              <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                  <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Permiso</th>
                  <th v-for="r in roles" :key="r.id" class="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide">{{ r.nombre }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in catalogo" :key="g.clave">
                  <tr class="bg-indigo-50/60 border-b border-gray-200">
                    <td :colspan="roles.length + 1" class="px-5 py-2 text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ g.nombre }}</td>
                  </tr>
                  <tr v-for="p in g.permisos" :key="p.clave" class="border-b border-gray-100">
                    <td class="px-5 py-2.5 text-sm">
                      <p class="font-medium text-gray-800">{{ p.nombre }}</p>
                      <p class="text-xs text-gray-500">{{ p.descripcion }}</p>
                    </td>
                    <td v-for="r in roles" :key="r.id" class="px-3 py-2.5 text-center">
                      <span v-if="r.permisos.includes(p.clave)" class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold" :aria-label="`${r.nombre}: sí`">✓</span>
                      <span v-else class="text-gray-300" :aria-label="`${r.nombre}: no`">—</span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- Cuenta -->
    <Modal
      :abierto="formCuenta.abierto"
      :titulo="formCuenta.id ? 'Editar cuenta' : 'Nueva cuenta del personal'"
      :descripcion="formCuenta.id ? undefined : 'La persona entra con su correo y la contraseña inicial; después puede cambiarla con el ícono de llave de la barra superior.'"
      ancho="md"
      :bloqueado="formCuenta.guardando"
      @cerrar="formCuenta.abierto = false"
    >
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cuenta-nombre">Nombre completo</label>
          <input id="cuenta-nombre" v-model="formCuenta.nombre" placeholder="Ej: Pedro Soto" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cuenta-email">Correo</label>
          <input id="cuenta-email" v-model="formCuenta.email" type="email" placeholder="nombre@umag.cl" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div v-if="!formCuenta.id">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cuenta-clave">Contraseña inicial</label>
          <div class="flex gap-2">
            <input id="cuenta-clave" v-model="formCuenta.password" class="flex-1 min-w-0 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono" />
            <button type="button" @click="copiar(formCuenta.password)" class="px-3 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 text-sm font-medium">Copiar</button>
          </div>
          <p class="text-xs text-gray-500 mt-1">Anótala antes de guardar: después no se vuelve a mostrar.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cuenta-rol">Rol</label>
          <select id="cuenta-rol" v-model="formCuenta.rol" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option v-for="r in roles" :key="r.clave" :value="r.clave">Nivel {{ r.nivel }} · {{ r.nombre }}</option>
          </select>
          <p v-if="rolElegido" class="text-xs text-indigo-800 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 mt-2">{{ rolElegido.descripcion ?? 'Sin descripción.' }}</p>
        </div>
      </div>
      <template #pie>
        <button @click="formCuenta.abierto = false" :disabled="formCuenta.guardando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button @click="pedirConfirmacionCuenta" :disabled="formCuenta.guardando" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60">
          {{ formCuenta.id ? 'Guardar cambios' : 'Crear cuenta' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="formCuenta.confirmando"
      :titulo="formCuenta.id ? '¿Guardar los cambios de la cuenta?' : '¿Crear esta cuenta?'"
      :confirmar="formCuenta.id ? 'Sí, guardar' : 'Sí, crear cuenta'"
      :cargando="formCuenta.guardando"
      @confirmar="guardarCuenta"
      @cancelar="formCuenta.confirmando = false"
    >
      <ul v-if="formCuenta.id" class="space-y-1.5">
        <li v-for="c in cambiosCuenta" :key="c.campo">
          {{ c.campo }}: <span class="line-through">{{ c.antes }}</span> → <strong class="text-gray-900">{{ c.despues }}</strong>
        </li>
      </ul>
      <p v-else>
        <strong class="text-gray-900">{{ formCuenta.nombre.trim() }}</strong> ({{ formCuenta.email.trim() }}) podrá entrar al panel con el rol
        <strong class="text-gray-900">{{ rolElegido?.nombre ?? formCuenta.rol }}</strong>. Anota la contraseña inicial antes de continuar: después no se vuelve a mostrar.
      </p>
      <p v-if="cambiaRolCuenta && rolElegido" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        <template v-if="rolElegido.acceso_total">Tendrá acceso total, incluida la gestión de cuentas, roles y configuración.</template>
        <template v-else>Verá y podrá hacer solo lo que permite este rol ({{ rolElegido.permisos.length }} de {{ totalPermisos }} permisos).</template>
      </p>
    </DialogoConfirmacion>

    <!-- Contraseña -->
    <Modal
      :abierto="formClave.abierto"
      titulo="Restablecer contraseña"
      :descripcion="`Para ${formClave.cuenta?.nombre ?? ''}. Sus sesiones abiertas se cerrarán.`"
      :bloqueado="formClave.guardando"
      @cerrar="formClave.abierto = false"
    >
      <p class="mb-4 rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-600">
        La contraseña actual no se puede ver: el sistema la guarda cifrada. Aquí se asigna una nueva, que no cambia hasta que confirmes.
      </p>
      <label class="block text-sm font-medium text-gray-700 mb-1" for="clave-personal">Contraseña nueva</label>
      <div class="flex gap-2">
        <input id="clave-personal" v-model="formClave.password" placeholder="Al menos 8 caracteres" autocomplete="off" class="flex-1 min-w-0 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono" />
        <button type="button" @click="formClave.password = generarClave()" class="px-3 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 text-sm font-medium">Generar</button>
        <button type="button" :disabled="!formClave.password" @click="copiar(formClave.password)" class="px-3 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 text-sm font-medium disabled:opacity-50">Copiar</button>
      </div>
      <p class="mt-1.5 text-xs text-gray-500">Escríbela tú o pulsa «Generar» para una al azar.</p>
      <template #pie>
        <button @click="formClave.abierto = false" :disabled="formClave.guardando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button
          @click="formClave.confirmando = true"
          :disabled="formClave.guardando || formClave.password.length < 8"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
        >
          Restablecer
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="formClave.confirmando"
      titulo="¿Restablecer la contraseña?"
      confirmar="Sí, restablecer"
      :cargando="formClave.guardando"
      @confirmar="guardarClave"
      @cancelar="formClave.confirmando = false"
    >
      <p>
        <strong class="text-gray-900">{{ formClave.cuenta?.nombre }}</strong> tendrá que entrar con la contraseña nueva y sus sesiones abiertas se cerrarán.
        Anótala antes de continuar: <span class="font-mono font-semibold text-gray-900">{{ formClave.password }}</span>
      </p>
    </DialogoConfirmacion>

    <!-- Activar / desactivar -->
    <DialogoConfirmacion
      :abierto="!!cuentaACambiar"
      :titulo="cuentaACambiar?.activo ? '¿Desactivar esta cuenta?' : '¿Reactivar esta cuenta?'"
      :confirmar="cuentaACambiar?.activo ? 'Sí, desactivar' : 'Sí, reactivar'"
      :tono="cuentaACambiar?.activo ? 'peligro' : 'exito'"
      :cargando="cambiandoEstado"
      @confirmar="confirmarCambioEstado"
      @cancelar="cuentaACambiar = null"
    >
      <p v-if="cuentaACambiar?.activo">
        <strong>{{ cuentaACambiar.nombre }}</strong> no podrá volver a entrar y su sesión abierta se cerrará de inmediato.
        Todo lo que registró se conserva, y puedes reactivarla cuando quieras.
      </p>
      <p v-else><strong>{{ cuentaACambiar?.nombre }}</strong> podrá volver a entrar con su correo y contraseña, con el rol que tenía.</p>
    </DialogoConfirmacion>

    <!-- Eliminar -->
    <DialogoConfirmacion
      :abierto="!!cuentaAEliminar"
      titulo="¿Eliminar esta cuenta?"
      confirmar="Eliminar cuenta"
      tono="peligro"
      :cargando="eliminandoCuenta"
      :deshabilitado="!confirmacionEscrita"
      @confirmar="confirmarEliminarCuenta"
      @cancelar="cuentaAEliminar = null"
    >
      <template v-if="cuentaAEliminar">
        <p>
          La cuenta de <strong>{{ cuentaAEliminar.nombre }}</strong> ({{ cuentaAEliminar.email }}) dejará de aparecer en Personal y Roles y no podrá
          volver a entrar. Esto no se puede deshacer desde el sistema.
        </p>
        <p class="mt-2">
          <strong>Su historial no se borra:</strong> los préstamos, multas, reservas y acciones que registró siguen en el sistema y en el Registro
          absoluto con su nombre. Su correo queda libre por si vuelve y hay que crearle una cuenta nueva.
        </p>
        <label class="mt-4 block text-sm font-medium text-gray-700" for="confirmar-eliminar">Para continuar, escribe <strong>confirmar</strong>:</label>
        <input
          id="confirmar-eliminar"
          v-model="textoConfirmacion"
          type="text"
          autocomplete="off"
          :disabled="eliminandoCuenta"
          class="mt-1 w-full rounded-lg border border-gray-300 px-4 py-2.5 text-gray-900 outline-none focus:ring-2 focus:ring-red-500"
          @keydown.enter.prevent="confirmarEliminarCuenta"
        />
      </template>
    </DialogoConfirmacion>

    <!-- Rol -->
    <Modal
      :abierto="formRol.abierto"
      :titulo="formRol.id ? `Rol: ${formRol.nombre || '(sin nombre)'}` : 'Nuevo rol'"
      :descripcion="formRol.accesoTotal ? 'Este rol tiene todos los permisos, incluida la gestión de cuentas y roles. Solo se puede cambiar su nombre y descripción.' : 'Marca lo que las personas con este rol pueden hacer. Lo que no marques quedará oculto y bloqueado para ellas.'"
      ancho="xl"
      :bloqueado="formRol.guardando"
      @cerrar="formRol.abierto = false"
    >
      <div class="grid grid-cols-1 md:grid-cols-[1fr_1.4fr_8rem] gap-3 mb-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="rol-nombre">Nombre del rol</label>
          <input id="rol-nombre" v-model="formRol.nombre" maxlength="100" placeholder="Ej: Ayudante de sala" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="rol-descripcion">Descripción</label>
          <input id="rol-descripcion" v-model="formRol.descripcion" maxlength="500" placeholder="Qué hace alguien con este rol" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div v-if="!formRol.accesoTotal">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="rol-nivel">Nivel</label>
          <input id="rol-nivel" v-model.number="formRol.nivel" type="number" min="2" max="9" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
      </div>

      <template v-if="!formRol.accesoTotal">
        <p class="text-xs text-gray-500 -mt-2 mb-4">El nivel ordena la jerarquía: 1 es el más alto (reservado para acceso total) y 9 el más bajo. Lo que cada rol puede hacer lo deciden los permisos marcados.</p>

        <div v-if="!formRol.id" class="flex flex-col sm:flex-row sm:items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4">
          <label class="text-sm font-medium text-gray-700" for="rol-copiar">Partir desde los permisos de:</label>
          <select id="rol-copiar" v-model="formRol.copiarDe" @change="copiarPermisosDe(formRol.copiarDe)" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
            <option value="">— Empezar sin permisos —</option>
            <option v-for="r in roles" :key="r.clave" :value="r.clave">{{ r.nombre }}</option>
          </select>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div v-for="g in catalogo" :key="g.clave" class="rounded-lg border border-gray-200 p-4">
            <p class="font-semibold text-gray-900 text-sm">{{ g.nombre }}</p>
            <p class="text-xs text-gray-500 mb-2">{{ g.descripcion }}</p>
            <label
              v-for="p in g.permisos"
              :key="p.clave"
              class="flex items-start gap-2.5 rounded-lg px-2 py-1.5"
              :class="p.solo_acceso_total ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50'"
            >
              <input
                type="checkbox"
                class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                :checked="formRol.permisos.has(p.clave)"
                :disabled="p.solo_acceso_total"
                @change="alternarPermiso(p.clave)"
              />
              <span>
                <span class="block text-sm font-medium text-gray-800">{{ p.nombre }}</span>
                <span class="block text-xs text-gray-500">{{ p.solo_acceso_total ? 'Solo el rol con acceso total puede tenerlo.' : p.descripcion }}</span>
              </span>
            </label>
          </div>
        </div>
      </template>

      <template #pie>
        <button
          v-if="formRol.id && !formRol.accesoTotal"
          @click="rolAEliminar = roles.find((r) => r.id === formRol.id) ?? null"
          :disabled="formRol.guardando"
          class="px-4 py-2.5 text-red-600 hover:bg-red-50 rounded-lg font-medium text-sm sm:mr-auto"
        >
          Eliminar rol
        </button>
        <button @click="formRol.abierto = false" :disabled="formRol.guardando" class="flex-1 sm:flex-none px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button @click="pedirConfirmacionRol" :disabled="formRol.guardando" class="flex-1 sm:flex-none px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60">
          {{ formRol.id ? 'Guardar cambios' : 'Crear rol' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="formRol.confirmando"
      :titulo="formRol.id ? '¿Guardar los cambios del rol?' : '¿Crear este rol?'"
      :confirmar="formRol.id ? 'Sí, guardar' : 'Sí, crear rol'"
      :cargando="formRol.guardando"
      @confirmar="guardarRol"
      @cancelar="formRol.confirmando = false"
    >
      <template v-if="formRol.id">
        <p class="mb-2">Rol <strong class="text-gray-900">{{ rolOriginal?.nombre }}</strong>:</p>
        <ul class="space-y-1.5">
          <li v-for="c in cambiosRol.datos" :key="c.campo">
            {{ c.campo }}: <span class="line-through">{{ c.antes }}</span> → <strong class="text-gray-900">{{ c.despues }}</strong>
          </li>
          <li v-if="cambiosRol.agregados.length">
            <span class="font-semibold text-emerald-700">Se agrega{{ cambiosRol.agregados.length === 1 ? '' : 'n' }} {{ cambiosRol.agregados.length }} permiso{{ cambiosRol.agregados.length === 1 ? '' : 's' }}:</span>
            {{ resumirLista(cambiosRol.agregados) }}
          </li>
          <li v-if="cambiosRol.quitados.length">
            <span class="font-semibold text-red-700">Se quita{{ cambiosRol.quitados.length === 1 ? '' : 'n' }} {{ cambiosRol.quitados.length }} permiso{{ cambiosRol.quitados.length === 1 ? '' : 's' }}:</span>
            {{ resumirLista(cambiosRol.quitados) }}
          </li>
        </ul>
        <p v-if="rolOriginal?.staff_count" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
          Afecta a {{ rolOriginal.staff_count }} {{ rolOriginal.staff_count === 1 ? 'cuenta' : 'cuentas' }} con este rol, desde su próxima acción.
        </p>
      </template>
      <template v-else>
        <p>
          <strong class="text-gray-900">{{ formRol.nombre.trim() }}</strong> tendrá {{ formRol.permisos.size }} de {{ totalPermisos }} permisos
          (nivel {{ formRol.nivel }}). Después podrás asignarlo a las cuentas del personal.
        </p>
        <p v-if="!formRol.permisos.size" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
          No marcaste ningún permiso: quienes tengan este rol solo verán el inicio.
        </p>
      </template>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!rolAEliminar"
      titulo="¿Eliminar este rol?"
      confirmar="Sí, eliminar"
      tono="peligro"
      :cargando="eliminandoRol"
      @confirmar="confirmarEliminarRol"
      @cancelar="rolAEliminar = null"
    >
      <p v-if="rolAEliminar?.staff_count">
        <strong>{{ rolAEliminar.nombre }}</strong> tiene {{ rolAEliminar.staff_count }} cuenta(s) asignada(s): primero cámbiales el rol.
        El sistema no permitirá eliminarlo mientras tanto.
      </p>
      <p v-else><strong>{{ rolAEliminar?.nombre }}</strong> se eliminará. Esta acción no se puede deshacer.</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
