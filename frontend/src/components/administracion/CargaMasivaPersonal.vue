<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { construirCsv, descargarCsv } from '@/utils/csv'
import { enlaceDeCorreo, extraerCorreos, generarClave, mensajeDeCuenta, nombreDesdeCorreo } from '@/utils/cuentasPersonal'
import { copiarTexto } from '@/utils/portapapeles'
import type { CuentaPersonal, Rol } from '@/types'

/**
 * Crear de una vez las cuentas de una lista de correos, en tres pasos: pegar la lista,
 * revisar el nombre y el rol de cada una, y entregar las contraseñas, que se generan al
 * crear y no se vuelven a mostrar. A quien ya tiene cuenta no se le toca nada.
 */
const props = defineProps<{ abierto: boolean; roles: Rol[]; cuentas: CuentaPersonal[] }>()
const emit = defineEmits<{ 'update:abierto': [valor: boolean]; cargado: [] }>()

type Fila = { email: string; nombre: string; rol: string }
type Creada = Fila & { password: string }

const toast = useToast()
const direccion = `${window.location.origin}/login`

const paso = ref<'pegar' | 'revisar' | 'listo'>('pegar')
const texto = ref('')
const filas = ref<Fila[]>([])
const creadas = ref<Creada[]>([])
const entregadas = ref(new Set<string>())
const confirmando = ref(false)
const guardando = ref(false)
const avisoCerrar = ref(false)

// Al abrir y al cerrar: las contraseñas no se quedan en la pantalla.
watch(
  () => props.abierto,
  () => {
    paso.value = 'pegar'
    texto.value = ''
    filas.value = []
    creadas.value = []
    entregadas.value = new Set()
    confirmando.value = false
    avisoCerrar.value = false
  },
)

const correos = computed(() => extraerCorreos(texto.value))
const cuentaPorCorreo = computed(() => new Map(props.cuentas.map((c) => [c.email, c])))
const nuevas = computed(() => filas.value.filter((f) => !cuentaPorCorreo.value.has(f.email)))
const puedeCrear = computed(() => nuevas.value.length > 0 && nuevas.value.every((f) => f.nombre.trim() && f.rol))

// Parte del rol con menos acceso: dar acceso total tiene que ser una decisión.
const rolInicial = computed(() => (props.roles.find((r) => r.clave === 'asistente') ?? props.roles.find((r) => !r.acceso_total) ?? props.roles[0])?.clave ?? '')
const rolDe = (clave: string) => props.roles.find((r) => r.clave === clave)

function revisar() {
  // Al volver atrás para agregar un correo se conserva lo que ya se había corregido.
  const previas = new Map(filas.value.map((f) => [f.email, f]))
  filas.value = correos.value.map((email) => previas.get(email) ?? { email, nombre: nombreDesdeCorreo(email), rol: rolInicial.value })
  paso.value = 'revisar'
}

function quitar(fila: Fila) {
  filas.value = filas.value.filter((f) => f !== fila)
}

function asignarATodas(evento: Event) {
  const selector = evento.target as HTMLSelectElement
  if (selector.value) nuevas.value.forEach((f) => (f.rol = selector.value))
  selector.value = ''
}

const porRol = computed(() =>
  props.roles.map((rol) => ({ rol, cuentas: nuevas.value.filter((f) => f.rol === rol.clave) })).filter((grupo) => grupo.cuentas.length),
)
const conAccesoTotal = computed(() => porRol.value.filter((grupo) => grupo.rol.acceso_total).flatMap((grupo) => grupo.cuentas))

async function crear() {
  guardando.value = true
  const cuentas = nuevas.value.map((f) => ({ ...f, nombre: f.nombre.trim(), password: generarClave() }))
  try {
    await api.post('/personal/lote', { cuentas })
    creadas.value = cuentas
    paso.value = 'listo'
    toast.success(cuentas.length === 1 ? 'Cuenta creada' : `${cuentas.length} cuentas creadas`)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron crear las cuentas'))
  } finally {
    guardando.value = false
    confirmando.value = false
    // También si falló: si alguien creó una de estas cuentas entretanto, su fila queda marcada.
    emit('cargado')
  }
}

// ---------- Entregar las contraseñas ----------
const sinEntregar = computed(() => creadas.value.filter((c) => !entregadas.value.has(c.email)).length)

function marcarEntregadas(...emails: string[]) {
  entregadas.value = new Set([...entregadas.value, ...emails])
}

async function copiarMensaje(cuenta: Creada) {
  if (await copiarTexto(mensajeDeCuenta(cuenta, direccion))) {
    marcarEntregadas(cuenta.email)
    toast.info(`Mensaje para ${cuenta.nombre} copiado`)
  } else {
    toast.error('No se pudo copiar; descarga el CSV')
  }
}

const COLUMNAS = ['Nombre', 'Correo', 'Rol', 'Contraseña inicial']
const filasDeLista = () => creadas.value.map((c) => [c.nombre, c.email, rolDe(c.rol)?.nombre ?? c.rol, c.password])

async function copiarLista() {
  const lineas = [COLUMNAS, ...filasDeLista()].map((columnas) => columnas.join('\t'))
  if (await copiarTexto(lineas.join('\n'))) {
    marcarEntregadas(...creadas.value.map((c) => c.email))
    toast.info('Lista copiada: se puede pegar en Excel')
  } else {
    toast.error('No se pudo copiar; descarga el CSV')
  }
}

function descargarLista() {
  const hoy = new Date().toLocaleDateString('sv-SE')
  descargarCsv(`cuentas-personal-${hoy}.csv`, construirCsv([{ titulo: `Cuentas del personal creadas el ${hoy}`, columnas: COLUMNAS, filas: filasDeLista() }]))
  marcarEntregadas(...creadas.value.map((c) => c.email))
}

function pedirCerrar() {
  if (sinEntregar.value) avisoCerrar.value = true
  else emit('update:abierto', false)
}
</script>

<template>
  <Modal
    :abierto="abierto"
    titulo="Cargar varias cuentas del personal"
    ancho="xl"
    pie-fijo
    :bloqueado="guardando"
    :cerrar-al-clic-afuera="false"
    @cerrar="pedirCerrar"
  >
    <div class="space-y-4 text-sm">
      <ol class="flex flex-wrap gap-2 text-xs font-medium text-gray-500">
        <li class="rounded-full px-3 py-1" :class="paso === 'pegar' ? 'bg-indigo-600 text-white' : 'bg-gray-100'">1. Pegar la lista</li>
        <li class="rounded-full px-3 py-1" :class="paso === 'revisar' ? 'bg-indigo-600 text-white' : 'bg-gray-100'">2. Revisar nombres y roles</li>
        <li class="rounded-full px-3 py-1" :class="paso === 'listo' ? 'bg-emerald-600 text-white' : 'bg-gray-100'">3. Entregar las contraseñas</li>
      </ol>

      <!-- Paso 1 -->
      <template v-if="paso === 'pegar'">
        <div>
          <label class="block font-medium text-gray-700 mb-1" for="carga-personal-lista">Lista de correos</label>
          <textarea
            id="carga-personal-lista"
            v-model="texto"
            rows="10"
            placeholder="ana.rojas@umag.cl&#10;luis.vera@umag.cl&#10;…"
            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
          />
          <p class="mt-1.5 text-xs text-gray-500">
            Puedes pegar el correo completo: se toman solo las direcciones.
            <strong v-if="correos.length" class="text-gray-900">{{ correos.length === 1 ? 'Se encontró 1 dirección.' : `Se encontraron ${correos.length} direcciones.` }}</strong>
          </p>
        </div>
      </template>

      <!-- Paso 2 -->
      <template v-else-if="paso === 'revisar'">
        <p class="text-gray-600">
          Los nombres salen del correo, sin tildes ni segundos nombres: corrígelos aquí si hace falta. Quita las direcciones que no correspondan, como la de una firma.
          Todavía no se ha creado nada.
        </p>

        <div class="flex flex-wrap items-center gap-2">
          <label class="font-medium text-gray-700" for="carga-personal-todas">Asignar a todas:</label>
          <select id="carga-personal-todas" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" @change="asignarATodas">
            <option value="">Elegir rol…</option>
            <option v-for="r in roles" :key="r.clave" :value="r.clave">{{ r.nombre }}</option>
          </select>
          <span class="text-xs text-gray-500">Después puedes cambiar el rol de cada persona en su fila.</span>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200">
          <table class="w-full min-w-[44rem]">
            <thead>
              <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                <th class="px-3 py-2">Nombre</th>
                <th class="px-3 py-2">Correo</th>
                <th class="px-3 py-2">Rol</th>
                <th class="px-3 py-2"><span class="sr-only">Quitar</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template v-for="f in filas" :key="f.email">
                <tr v-if="cuentaPorCorreo.has(f.email)" class="bg-gray-50 text-gray-500">
                  <td class="px-3 py-2">{{ cuentaPorCorreo.get(f.email)?.nombre }}</td>
                  <td class="px-3 py-2">{{ f.email }}</td>
                  <td class="px-3 py-2" colspan="2">
                    Ya tiene cuenta ({{ cuentaPorCorreo.get(f.email)?.rol_nombre }}{{ cuentaPorCorreo.get(f.email)?.activo ? '' : ', desactivada' }}): no se modifica.
                  </td>
                </tr>
                <tr v-else>
                  <td class="px-3 py-2">
                    <input
                      v-model="f.nombre"
                      :aria-label="`Nombre de ${f.email}`"
                      maxlength="255"
                      class="w-full px-3 py-1.5 border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                      :class="f.nombre.trim() ? 'border-gray-300' : 'border-red-400'"
                    />
                  </td>
                  <td class="px-3 py-2 text-gray-700">{{ f.email }}</td>
                  <td class="px-3 py-2">
                    <select v-model="f.rol" :aria-label="`Rol de ${f.email}`" class="w-full px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                      <option v-for="r in roles" :key="r.clave" :value="r.clave">{{ r.nombre }}</option>
                    </select>
                    <p v-if="rolDe(f.rol)?.acceso_total" class="mt-1 text-xs font-medium text-amber-700">Acceso total</p>
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="font-medium text-red-600 hover:text-red-700" @click="quitar(f)">Quitar</button>
                  </td>
                </tr>
              </template>
              <tr v-if="!filas.length">
                <td colspan="4" class="px-3 py-6 text-center text-gray-400">No quedan direcciones en la lista.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- Paso 3 -->
      <template v-else>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
          <p class="font-semibold">{{ creadas.length === 1 ? 'Se creó 1 cuenta.' : `Se crearon ${creadas.length} cuentas.` }} Entrega las contraseñas antes de cerrar: después no se vuelven a mostrar.</p>
          <p class="mt-1 text-xs">
            «Escribir correo» abre tu programa de correo con el mensaje listo para esa persona; si no se abre, usa «Copiar mensaje». La lista y el CSV traen las contraseñas sin cifrar: bórralos cuando termines.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-700 hover:bg-gray-100" @click="copiarLista">Copiar lista</button>
          <button type="button" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-700 hover:bg-gray-100" @click="descargarLista">Descargar CSV</button>
          <span class="ml-auto text-xs text-gray-500">{{ sinEntregar ? `Faltan ${sinEntregar} de ${creadas.length}` : 'Todas entregadas' }}</span>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200">
          <table class="w-full min-w-[44rem]">
            <thead>
              <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                <th class="px-3 py-2">Nombre</th>
                <th class="px-3 py-2">Rol</th>
                <th class="px-3 py-2">Contraseña</th>
                <th class="px-3 py-2"><span class="sr-only">Entregar</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="c in creadas" :key="c.email">
                <td class="px-3 py-2">
                  <p class="font-medium text-gray-900">{{ c.nombre }}</p>
                  <p class="text-xs text-gray-500">{{ c.email }}</p>
                </td>
                <td class="px-3 py-2 text-gray-700">{{ rolDe(c.rol)?.nombre ?? c.rol }}</td>
                <td class="px-3 py-2 font-mono text-gray-900">{{ c.password }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <span v-if="entregadas.has(c.email)" class="mr-3 font-bold text-emerald-700" aria-label="Entregada">✓</span>
                  <a :href="enlaceDeCorreo(c, direccion)" class="mr-3 font-medium text-indigo-700 hover:text-indigo-800" @click="marcarEntregadas(c.email)">Escribir correo</a>
                  <button type="button" class="font-medium text-indigo-700 hover:text-indigo-800" @click="copiarMensaje(c)">Copiar mensaje</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <template #pie>
      <template v-if="paso === 'pegar'">
        <button type="button" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" @click="pedirCerrar">Cancelar</button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-50"
          :disabled="!correos.length"
          @click="revisar"
        >
          Revisar
        </button>
      </template>
      <template v-else-if="paso === 'revisar'">
        <button type="button" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" @click="paso = 'pegar'">Volver</button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-50"
          :disabled="!puedeCrear"
          @click="confirmando = true"
        >
          {{ nuevas.length === 1 ? 'Crear 1 cuenta' : `Crear ${nuevas.length} cuentas` }}
        </button>
      </template>
      <button v-else type="button" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="pedirCerrar">Listo</button>
    </template>
  </Modal>

  <DialogoConfirmacion
    :abierto="confirmando"
    :titulo="nuevas.length === 1 ? '¿Crear esta cuenta?' : `¿Crear estas ${nuevas.length} cuentas?`"
    confirmar="Sí, crear"
    :cargando="guardando"
    @confirmar="crear"
    @cancelar="confirmando = false"
  >
    <ul class="space-y-1.5">
      <li v-for="grupo in porRol" :key="grupo.rol.clave">
        <strong class="text-gray-900">{{ grupo.cuentas.length }}</strong> con el rol <strong class="text-gray-900">{{ grupo.rol.nombre }}</strong>
      </li>
    </ul>
    <p v-if="conAccesoTotal.length" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
      Tendrán acceso total, incluida la gestión de cuentas, roles y configuración: {{ conAccesoTotal.map((f) => f.nombre.trim()).join(', ') }}.
    </p>
  </DialogoConfirmacion>

  <DialogoConfirmacion
    :abierto="avisoCerrar"
    titulo="¿Cerrar sin entregar todas las contraseñas?"
    confirmar="Sí, cerrar"
    cancelar="Seguir aquí"
    tono="peligro"
    @confirmar="emit('update:abierto', false)"
    @cancelar="avisoCerrar = false"
  >
    <p>
      Faltan {{ sinEntregar }} de {{ creadas.length }}. Las contraseñas no se vuelven a mostrar: para esas personas tendrás que usar «Restablecer contraseña».
    </p>
  </DialogoConfirmacion>
</template>
