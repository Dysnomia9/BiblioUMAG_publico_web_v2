<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import FondoMultas from '@/components/multas/FondoMultas.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import Paginacion from '@/components/ui/Paginacion.vue'
import DetalleDeuda from '@/components/multas/DetalleDeuda.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { largoSegunModo, placeholderSegunModo } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { useListadoPaginado } from '@/composables/useListadoPaginado'
import { contieneCadaPalabra, sinTildes } from '@/utils/texto'
import { descargarExcel } from '@/utils/excel'
import { ESTADOS_MULTA as ESTADOS, TIPOS_MULTA as TIPOS, fechaMulta as formatFecha, formatMonto, origenMulta } from '@/utils/multas'
import type { DeudaUsuario, EstadoMulta, Multa, MultasResumen, Paginado, TipoMulta, Usuario } from '@/types'

const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const pestana = ref<'deudas' | 'listado'>('deudas')
const guiaAbierta = ref(false)

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const iniciales = (nombre: string, apellido: string) => `${nombre.trim().charAt(0)}${apellido.trim().charAt(0)}`.toUpperCase()
const TONOS_AVATAR = ['bg-indigo-50 text-indigo-700', 'bg-amber-50 text-amber-800', 'bg-teal-50 text-teal-700', 'bg-violet-50 text-violet-700']
const tonoAvatar = (texto: string) => TONOS_AVATAR[[...texto].reduce((suma, letra) => suma + letra.charCodeAt(0), 0) % TONOS_AVATAR.length]
function diaSemana(iso: string) {
  const dia = new Date(iso).toLocaleDateString('es-CL', { weekday: 'long' })
  return dia.charAt(0).toUpperCase() + dia.slice(1)
}
const errorResumen = ref(false)

const resumen = ref<MultasResumen | null>(null)
const busquedaDeudas = ref('')
const filtros = reactive({ q: '', estado: 'pendiente' as EstadoMulta | '', tipo: '' as TipoMulta | '' })

const deudasFiltradas = computed(() => {
  if (!resumen.value) return []
  const q = sinTildes(busquedaDeudas.value.trim())
  if (!q) return resumen.value.usuarios
  return resumen.value.usuarios.filter((u) => contieneCadaPalabra(`${u.nombre} ${u.apellido}`, q) || u.rut.toLowerCase().includes(q))
})

// Las deudas llegan todas juntas (son solo quienes deben); se muestran de a 25.
const DEUDAS_POR_PAGINA = 25
const paginaDeudas = ref(1)
const deudasVisibles = computed(() => deudasFiltradas.value.slice((paginaDeudas.value - 1) * DEUDAS_POR_PAGINA, paginaDeudas.value * DEUDAS_POR_PAGINA))
watch(busquedaDeudas, () => (paginaDeudas.value = 1))
watch(deudasFiltradas, (lista) => {
  // Tras pagar la última deuda de la última página, se vuelve a la anterior.
  const ultima = Math.max(1, Math.ceil(lista.length / DEUDAS_POR_PAGINA))
  if (paginaDeudas.value > ultima) paginaDeudas.value = ultima
})

async function cargarResumen() {
  try {
    const { data } = await api.get<MultasResumen>('/multas/resumen')
    resumen.value = data
    errorResumen.value = false
  } catch {
    errorResumen.value = true
  }
}

// «Todas las multas» crece con los años: el servidor la entrega de a 25, las más nuevas primero.
const {
  filas: multas,
  total: totalMultas,
  porPagina: multasPorPagina,
  pagina: paginaMultas,
  cargando: cargandoListado,
  error: errorListado,
  cargar: cargarListado,
  reiniciar: reiniciarListado,
} = useListadoPaginado<Multa>(async (p) => {
  const { data } = await api.get<Paginado<Multa>>('/multas', {
    params: { q: filtros.q.trim() || undefined, estado: filtros.estado || undefined, tipo: filtros.tipo || undefined, page: p },
  })
  return data
})
const apiError = computed(() => errorResumen.value || errorListado.value)

let temporizador: ReturnType<typeof setTimeout> | undefined
watch(() => [filtros.estado, filtros.tipo], reiniciarListado)
watch(
  () => filtros.q,
  () => {
    clearTimeout(temporizador)
    temporizador = setTimeout(reiniciarListado, 300)
  },
)

// «Ver detalle» de la deuda: un popup con cada multa, para pagarla o anularla ahí mismo.
const deudaAbierta = ref<DeudaUsuario | null>(null)
// Sube tras pagar o anular, para que el detalle abierto se recargue.
const versionDeuda = ref(0)

/** Todas las multas de la persona (también pagadas y anuladas) en la pestaña del listado. */
function verEnListado(rut: string) {
  deudaAbierta.value = null
  filtros.q = rut
  filtros.estado = ''
  pestana.value = 'listado'
}

onMounted(async () => {
  const rut = typeof route.query.rut === 'string' ? route.query.rut : ''
  if (rut) verEnListado(rut)
  await Promise.all([cargarResumen(), cargarListado()])
})

async function recargar() {
  await Promise.all([cargarResumen(), cargarListado()])
}

// ---- Registrar pago ----
const multaAPagar = ref<Multa | null>(null)
const pagando = ref(false)

async function confirmarPago() {
  if (!multaAPagar.value) return
  pagando.value = true
  try {
    await api.patch(`/multas/${multaAPagar.value.id}/pagar`)
    toast.success(`Pago de ${formatMonto(multaAPagar.value.monto)} registrado`)
    multaAPagar.value = null
    versionDeuda.value++
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar el pago'))
  } finally {
    pagando.value = false
  }
}

// ---- Anular ----
const multaAAnular = ref<Multa | null>(null)
const motivoAnulacion = ref('')
const anulando = ref(false)

function pedirAnulacion(m: Multa) {
  multaAAnular.value = m
  motivoAnulacion.value = ''
}

async function confirmarAnulacion() {
  if (!multaAAnular.value) return
  if (motivoAnulacion.value.trim().length < 5) {
    toast.error('Escribe el motivo de la anulación (al menos 5 caracteres)')
    return
  }
  anulando.value = true
  try {
    await api.patch(`/multas/${multaAAnular.value.id}/anular`, { motivo: motivoAnulacion.value.trim() })
    toast.success('Multa anulada. Queda en el historial con el motivo.')
    multaAAnular.value = null
    versionDeuda.value++
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo anular la multa'))
  } finally {
    anulando.value = false
  }
}

// ---- Exportar a Excel ----
// Lo que muestra la pestaña abierta, con sus filtros: la deuda por usuario o todas las
// multas (todas las páginas, de a 100), con el motivo, el detalle y el préstamo que la originó.
const confirmandoExportar = ref(false)
const exportando = ref(false)
const progreso = ref('')
const MAXIMO_EXPORTACION = 20000
const totalAExportar = computed(() => (pestana.value === 'deudas' ? deudasFiltradas.value.length : totalMultas.value))

function pedirExportar() {
  if (!totalAExportar.value) {
    toast.error(pestana.value === 'deudas' ? 'No hay deudas para exportar' : 'No hay multas para exportar con los filtros actuales')
    return
  }
  if (totalAExportar.value > MAXIMO_EXPORTACION) {
    toast.error(`Son ${totalAExportar.value.toLocaleString('es-CL')} multas: acota los filtros (máximo ${MAXIMO_EXPORTACION.toLocaleString('es-CL')} por archivo).`)
    return
  }
  confirmandoExportar.value = true
}

const fechaHoy = () => new Date().toLocaleDateString('sv-SE')
const conAtraso = (dias: number | null) => (dias ? `${dias} ${dias === 1 ? 'día' : 'días'}` : '')

async function exportarExcel() {
  exportando.value = true
  try {
    if (pestana.value === 'deudas') {
      await descargarExcel(`deuda-por-usuario_${fechaHoy()}.xlsx`, [
        {
          titulo: 'Deuda por usuario',
          columnas: ['Usuario', 'RUT', 'Correo', 'N° de multas', 'Monto pendiente (CLP)', 'Multa más antigua'],
          // «desde» llega como «2026-10-30 12:00:00», que Safari no lee sin la T.
          filas: deudasFiltradas.value.map((u) => [`${u.nombre} ${u.apellido}`, u.rut, u.email ?? '', u.cantidad_multas, u.monto_total, formatFecha(u.desde.replace(' ', 'T'))]),
        },
      ])
      toast.success(`Excel descargado: ${deudasFiltradas.value.length.toLocaleString('es-CL')} personas con deuda`)
      confirmandoExportar.value = false
      return
    }

    const todas: Multa[] = []
    let numero = 1
    let ultima = 1
    do {
      progreso.value = `Trayendo las multas: ${numero} de ${ultima}…`
      const { data } = await api.get<Paginado<Multa>>('/multas', {
        params: { q: filtros.q.trim() || undefined, estado: filtros.estado || undefined, tipo: filtros.tipo || undefined, page: numero, por_pagina: 100 },
      })
      todas.push(...data.data)
      ultima = data.last_page
      numero++
    } while (numero <= ultima)

    progreso.value = 'Armando el Excel…'
    await descargarExcel(`multas_${filtros.estado || 'todas'}_${fechaHoy()}.xlsx`, [
      {
        titulo: 'Multas',
        columnas: [
          'Fecha', 'Usuario', 'RUT', 'Correo', 'Motivo', 'Detalle', 'Material', 'Prestado el', 'Debía volver el', 'Volvió el', 'Atraso',
          'Monto (CLP)', 'Estado', 'Pagada el', 'Cobrada por', 'Anulada el', 'Anulada por', 'Motivo de la anulación', 'Origen',
        ],
        filas: todas.map((m) => [
          formatFecha(m.created_at),
          `${m.usuario?.nombre ?? ''} ${m.usuario?.apellido ?? ''}`.trim(),
          m.usuario?.rut ?? '',
          m.usuario?.email ?? '',
          TIPOS[m.tipo],
          m.detalle ?? '',
          m.prestamo?.libro_titulo ?? '',
          m.prestamo ? formatFecha(m.prestamo.fecha_prestamo) : '',
          m.prestamo?.fecha_devolucion ? formatFecha(m.prestamo.fecha_devolucion) : '',
          m.prestamo?.fecha_devolucion_real ? formatFecha(m.prestamo.fecha_devolucion_real) : '',
          conAtraso(m.dias_atraso),
          m.monto,
          ESTADOS[m.estado].label,
          m.estado === 'pagada' ? formatFecha(m.pagada_en) : '',
          m.estado === 'pagada' ? (m.pagada_por?.nombre ?? '') : '',
          m.estado === 'anulada' ? formatFecha(m.anulada_en) : '',
          m.estado === 'anulada' ? (m.anulada_por?.nombre ?? '') : '',
          m.motivo_anulacion ?? '',
          origenMulta(m),
        ]),
      },
    ])
    toast.success(`Excel descargado: ${todas.length.toLocaleString('es-CL')} ${todas.length === 1 ? 'multa' : 'multas'}`)
    confirmandoExportar.value = false
  } catch {
    toast.error('No se pudo generar el Excel')
  } finally {
    exportando.value = false
    progreso.value = ''
  }
}

// ---- Cursar multa manual ----
const nueva = reactive({
  abierto: false,
  confirmando: false,
  guardando: false,
  rut: '',
  buscando: false,
  usuario: null as Usuario | null,
  errorRut: '',
  tipo: 'deterioro' as Exclude<TipoMulta, 'atraso'>,
  monto: null as number | null,
  detalle: '',
})

// RUT, o pasaporte/DNI de un estudiante extranjero.
const { modo: modoRutMulta, escribir: escribirRutMulta, error: errorRutMulta } = useModoDocumento(toRef(nueva, 'rut'))

function abrirNueva() {
  Object.assign(nueva, { abierto: true, confirmando: false, rut: '', usuario: null, errorRut: '', tipo: 'deterioro', monto: null, detalle: '' })
  modoRutMulta.value = 'rut'
}

async function buscarUsuarioMulta() {
  const error = errorRutMulta()
  if (error) {
    nueva.errorRut = error
    return
  }
  nueva.buscando = true
  nueva.errorRut = ''
  try {
    const { data } = await api.get<Usuario>(`/usuarios/rut/${encodeURIComponent(nueva.rut)}`)
    nueva.usuario = data
  } catch (e: any) {
    nueva.usuario = null
    nueva.errorRut = e?.response?.status === 404 ? `No hay ningún usuario con ese ${modoRutMulta.value === 'rut' ? 'RUT' : 'pasaporte o DNI'}.` : mensajeDeError(e, 'No se pudo buscar el usuario')
  } finally {
    nueva.buscando = false
  }
}

function pedirConfirmacionNueva() {
  if (!nueva.usuario) {
    toast.error('Primero busca al usuario por su RUT')
    return
  }
  if (!nueva.monto || nueva.monto < 1) {
    toast.error('Indica el monto de la multa en pesos')
    return
  }
  if (nueva.detalle.trim().length < 5) {
    toast.error('Describe el motivo (ej: «Pérdida del libro Cálculo I»)')
    return
  }
  nueva.confirmando = true
}

async function guardarNueva() {
  if (!nueva.usuario || !nueva.monto) return
  nueva.guardando = true
  try {
    await api.post('/multas', { usuario_id: nueva.usuario.id, tipo: nueva.tipo, monto: Math.round(nueva.monto), detalle: nueva.detalle.trim() })
    toast.success(`Multa de ${formatMonto(nueva.monto)} cursada a ${nueva.usuario.nombre} ${nueva.usuario.apellido}`)
    Object.assign(nueva, { abierto: false, confirmando: false })
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cursar la multa'))
    nueva.confirmando = false
  } finally {
    nueva.guardando = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <!-- La caja de título con motivo propio: boletas y una moneda. -->
      <div class="relative mb-5 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoMultas />
        <div class="relative flex flex-wrap items-center justify-between gap-3 px-6 py-4">
          <div class="min-w-0">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Atención de público</span>
            </nav>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Multas</h1>
            <p class="mt-0.5 text-sm text-white/75">Deudas de los usuarios con la biblioteca: pendientes, pagadas y anuladas</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="flex h-9 w-9 items-center justify-center rounded-full border border-white/30 bg-blanco/10 text-white transition-colors hover:bg-blanco/20"
              :aria-label="guiaAbierta ? 'Ocultar la ayuda' : '¿Cómo se usa esta pantalla?'"
              :aria-expanded="guiaAbierta"
              :title="guiaAbierta ? 'Ocultar la ayuda' : '¿Cómo se usa esta pantalla?'"
              @click="guiaAbierta = !guiaAbierta"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
            </button>
            <button
              type="button"
              :disabled="exportando"
              class="rounded-xl border border-white/30 bg-blanco/10 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blanco/20 disabled:opacity-60"
              @click="pedirExportar"
            >
              Exportar a Excel
            </button>
            <button
              v-if="auth.puede('multas.crear')"
              class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50"
              @click="abrirNueva"
            >
              + Cursar multa
            </button>
          </div>
        </div>
      </div>

      <GuiaUso
        v-model:abierta="guiaAbierta"
        :con-boton="false"
        :pasos="[
          'Las multas por <strong>atraso</strong> se generan solas al registrar una devolución fuera de plazo.',
          'En «Deuda por usuario», <strong>Ver detalle</strong> muestra cada multa de la persona (fecha, motivo y si se importó de Horizon) y permite pagarla o anularla ahí mismo.',
          'Cuando el usuario paga, pulse <strong>Marcar pagada</strong>: queda registrado quién lo hizo y cuándo.',
          'Si una multa se cursó por error, <strong>anúlela</strong> indicando el motivo: no se borra, queda en el historial.',
          'Mientras tenga multas <strong>pendientes</strong>, la persona no puede llevarse ni reservar material. Al pagar o anular, se desbloquea al instante.',
        ]"
        consejo="Registrar pagos, anular y cursar multas dependen del rol de cada cuenta (Menú Gestiones Admin → Personal y Roles)."
      />

      <ApiErrorBanner v-if="apiError" />

      <div v-if="resumen" class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-t-[3px] border-gray-200 border-t-indigo-600 bg-white p-4 shadow-sm">
          <p class="text-[11px] font-bold uppercase tracking-wider text-gray-600">Usuarios con deuda</p>
          <p class="mt-1 font-serif text-3xl font-bold tabular-nums text-gray-900">{{ resumen.total_usuarios }}</p>
        </div>
        <div class="rounded-2xl border border-t-[3px] border-gray-200 border-t-amber-500 bg-white bg-gradient-to-br from-white to-amber-50 p-4 shadow-sm">
          <p class="text-[11px] font-bold uppercase tracking-wider text-gray-600">Multas pendientes</p>
          <p class="mt-1 font-serif text-3xl font-bold tabular-nums text-gray-900">{{ resumen.total_multas }}</p>
        </div>
        <div class="rounded-2xl border border-t-[3px] border-gray-200 border-t-red-600 bg-white bg-gradient-to-br from-white to-red-50 p-4 shadow-sm">
          <p class="text-[11px] font-bold uppercase tracking-wider text-gray-600">Monto total pendiente</p>
          <p class="mt-1 font-serif text-3xl font-bold tabular-nums text-red-700">{{ formatMonto(resumen.monto_total) }}</p>
        </div>
      </div>

      <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-indigo-50/40 p-3">
          <div class="flex flex-wrap gap-1.5 rounded-xl bg-gray-200 p-1.5" role="tablist" aria-label="Vista de multas">
            <button
              type="button"
              role="tab"
              :aria-selected="pestana === 'deudas'"
              class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-semibold shadow-sm transition-colors"
              :class="pestana === 'deudas' ? 'border-indigo-700 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-800 hover:border-indigo-300 hover:text-indigo-700'"
              @click="pestana = 'deudas'"
            >
              Deuda por usuario
              <span v-if="resumen" class="rounded-full px-2 text-[11px] font-bold tabular-nums" :class="pestana === 'deudas' ? 'bg-blanco/20 text-white' : 'bg-gray-100 text-gray-600'">{{ resumen.total_usuarios }}</span>
            </button>
            <button
              type="button"
              role="tab"
              :aria-selected="pestana === 'listado'"
              class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-semibold shadow-sm transition-colors"
              :class="pestana === 'listado' ? 'border-indigo-700 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-800 hover:border-indigo-300 hover:text-indigo-700'"
              @click="pestana = 'listado'"
            >
              Todas las multas
              <span v-if="resumen" class="rounded-full px-2 text-[11px] font-bold tabular-nums" :class="pestana === 'listado' ? 'bg-blanco/20 text-white' : 'bg-gray-100 text-gray-600'">{{ resumen.total_multas }}</span>
            </button>
          </div>

          <input
            v-if="pestana === 'deudas'"
            v-model="busquedaDeudas"
            type="text"
            placeholder="Buscar por nombre o RUT"
            aria-label="Buscar por nombre o RUT"
            class="h-10 w-full rounded-xl border border-gray-200 bg-white px-4 text-sm outline-none focus:ring-2 focus:ring-indigo-500 sm:w-80"
          />
          <div v-else class="flex w-full flex-wrap gap-2 sm:w-auto">
            <input
              v-model="filtros.q"
              type="text"
              placeholder="Buscar por RUT o nombre"
              aria-label="Buscar por RUT o nombre"
              class="h-10 min-w-0 flex-1 rounded-xl border border-gray-200 bg-white px-4 text-sm outline-none focus:ring-2 focus:ring-indigo-500 sm:w-64 sm:flex-none"
            />
            <select v-model="filtros.estado" aria-label="Filtrar por estado" class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="pendiente">Pendientes</option>
              <option value="pagada">Pagadas</option>
              <option value="anulada">Anuladas</option>
              <option value="">Todos los estados</option>
            </select>
            <select v-model="filtros.tipo" aria-label="Filtrar por motivo" class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="">Todos los motivos</option>
              <option v-for="(label, clave) in TIPOS" :key="clave" :value="clave">{{ label }}</option>
            </select>
          </div>
        </div>

        <!-- Deuda por usuario -->
        <div v-if="pestana === 'deudas'" class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                <th class="px-5 py-2.5">Usuario</th>
                <th class="px-5 py-2.5">RUT</th>
                <th class="px-5 py-2.5">Correo</th>
                <th class="px-5 py-2.5 text-center">Multas</th>
                <th class="px-5 py-2.5">Monto pendiente</th>
                <th class="px-5 py-2.5 text-right">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="u in deudasVisibles" :key="u.usuario_id" class="even:bg-gray-50/50">
                <td class="px-5 py-2.5">
                  <span class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="tonoAvatar(u.nombre + u.apellido)" aria-hidden="true">{{ iniciales(u.nombre, u.apellido) }}</span>
                    <span class="text-sm font-semibold text-gray-900">{{ u.nombre }} {{ u.apellido }}</span>
                  </span>
                </td>
                <td class="px-5 py-2.5 whitespace-nowrap"><span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 font-mono text-xs text-gray-700">{{ u.rut }}</span></td>
                <td class="px-5 py-2.5 text-sm">
                  <a v-if="u.email" :href="`mailto:${u.email}`" class="break-all text-indigo-700 hover:underline">{{ u.email }}</a>
                  <span v-else class="italic text-gray-400">Sin correo</span>
                </td>
                <td class="px-5 py-2.5 text-center">
                  <span class="inline-block min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="u.cantidad_multas > 1 ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700'">{{ u.cantidad_multas }}</span>
                </td>
                <td class="px-5 py-2.5 whitespace-nowrap text-sm font-bold tabular-nums text-red-700">{{ formatMonto(u.monto_total) }}</td>
                <td class="px-5 py-2.5 text-right">
                  <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-indigo-50 py-1 pl-3 pr-1 text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-100" @click="deudaAbierta = u">
                    Ver detalle
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white" aria-hidden="true">
                      <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </span>
                  </button>
                </td>
              </tr>
              <tr v-if="!deudasFiltradas.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin multas pendientes.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Todas las multas -->
        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                <th class="px-5 py-2.5">Fecha</th>
                <th class="px-5 py-2.5">Usuario</th>
                <th class="px-5 py-2.5">Motivo</th>
                <th class="px-5 py-2.5 text-right">Monto</th>
                <th class="px-5 py-2.5">Estado</th>
                <th class="px-5 py-2.5 text-right">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="m in multas" :key="m.id" class="align-top even:bg-gray-50/50">
                <td class="px-5 py-2.5 whitespace-nowrap">
                  <span class="block text-sm font-bold tabular-nums text-gray-900">{{ formatFecha(m.created_at) }}</span>
                  <span class="block text-xs text-gray-500">{{ diaSemana(m.created_at) }}</span>
                </td>
                <td class="px-5 py-2.5 text-sm text-gray-900">
                  <div class="font-semibold">{{ m.usuario?.nombre }} {{ m.usuario?.apellido }}</div>
                  <span class="mt-0.5 inline-block rounded border border-gray-200 bg-gray-50 px-1.5 font-mono text-[11px] text-gray-700">{{ m.usuario?.rut }}</span>
                  <a
                    v-if="m.usuario?.email"
                    :href="`mailto:${m.usuario.email}`"
                    class="mt-0.5 block break-all text-xs text-indigo-700 hover:underline"
                  >
                    {{ m.usuario.email }}
                  </a>
                </td>
                <td class="px-5 py-2.5 max-w-xs text-sm text-gray-700">
                  <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    {{ TIPOS[m.tipo] }}
                  </span>
                  <p class="mt-1 text-xs text-gray-500">{{ m.detalle }}</p>
                </td>
                <td class="px-5 py-2.5 whitespace-nowrap text-right text-sm font-bold tabular-nums" :class="m.estado === 'pendiente' ? 'text-red-700' : 'text-gray-500'">{{ formatMonto(m.monto) }}</td>
                <td class="px-5 py-2.5 text-sm">
                  <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="ESTADOS[m.estado].cls">
                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    {{ ESTADOS[m.estado].label }}
                  </span>
                  <p v-if="m.estado === 'pagada'" class="mt-1 text-xs text-gray-500">{{ formatFecha(m.pagada_en) }} · {{ m.pagada_por?.nombre ?? '—' }}</p>
                  <p v-if="m.estado === 'anulada'" class="mt-1 text-xs text-gray-500">{{ m.anulada_por?.nombre ?? '—' }}: «{{ m.motivo_anulacion }}»</p>
                </td>
                <td class="px-5 py-2.5 text-right">
                  <div v-if="m.estado === 'pendiente'" class="flex flex-wrap justify-end gap-1.5">
                    <button v-if="auth.puede('multas.cobrar')" type="button" class="whitespace-nowrap rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700" @click="multaAPagar = m">Marcar pagada</button>
                    <button v-if="auth.puede('multas.anular')" type="button" class="whitespace-nowrap rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-50" @click="pedirAnulacion(m)">Anular</button>
                    <span v-if="!auth.puede('multas.cobrar') && !auth.puede('multas.anular')" class="text-xs text-gray-400">—</span>
                  </div>
                </td>
              </tr>
              <tr v-if="!cargandoListado && !multas.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin multas que coincidan con los filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <Paginacion
        v-if="pestana === 'deudas'"
        v-model="paginaDeudas"
        class="mt-4"
        :total="deudasFiltradas.length"
        :por-pagina="DEUDAS_POR_PAGINA"
        singular="persona con deuda"
        plural="personas con deuda"
      />
      <Paginacion v-else v-model="paginaMultas" class="mt-4" :total="totalMultas" :por-pagina="multasPorPagina" singular="multa" plural="multas" />
    </div>

    <!-- Antes que los diálogos de pagar y anular, para que se abran encima del detalle. -->
    <DetalleDeuda
      :deuda="deudaAbierta"
      :version="versionDeuda"
      @cerrar="deudaAbierta = null"
      @pagar="multaAPagar = $event"
      @anular="pedirAnulacion"
      @ver-todas="verEnListado"
    />

    <DialogoConfirmacion
      :abierto="!!multaAPagar"
      titulo="¿Confirmar pago de multa?"
      confirmar="Sí, pagada"
      tono="exito"
      :cargando="pagando"
      @confirmar="confirmarPago"
      @cancelar="multaAPagar = null"
    >
      <p class="mb-2">
        Se registrará el pago de <strong>{{ formatMonto(multaAPagar?.monto ?? 0) }}</strong> de
        <strong>{{ multaAPagar?.usuario?.nombre }} {{ multaAPagar?.usuario?.apellido }}</strong>.
      </p>
      <p>Confirma solo si el usuario ya pagó: queda registrado a tu nombre.</p>
    </DialogoConfirmacion>

    <Modal
      :abierto="!!multaAAnular"
      titulo="Anular multa"
      descripcion="La multa deja de cobrarse pero no se borra: queda en el historial con el motivo y tu nombre."
      ancho="md"
      :bloqueado="anulando"
      @cerrar="multaAAnular = null"
    >
      <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-4">
        <p class="font-medium text-gray-900">{{ multaAAnular?.usuario?.nombre }} {{ multaAAnular?.usuario?.apellido }} · {{ formatMonto(multaAAnular?.monto ?? 0) }}</p>
        <p class="text-gray-500">{{ multaAAnular?.detalle }}</p>
      </div>
      <label class="block text-sm font-medium text-gray-700 mb-1" for="motivo-anulacion">Motivo de la anulación</label>
      <textarea
        id="motivo-anulacion"
        v-model="motivoAnulacion"
        rows="3"
        maxlength="500"
        placeholder="Ej: el libro se devolvió a tiempo y el lector registró mal la fecha"
        class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
      />
      <template #pie>
        <button @click="multaAAnular = null" :disabled="anulando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button
          @click="confirmarAnulacion"
          :disabled="anulando || motivoAnulacion.trim().length < 5"
          class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
        >
          {{ anulando ? 'Anulando…' : 'Sí, anular' }}
        </button>
      </template>
    </Modal>

    <Modal
      :abierto="nueva.abierto"
      titulo="Cursar una multa"
      descripcion="Para deterioro, pérdida de material u otro motivo. Las multas por atraso se generan solas al devolver."
      ancho="md"
      :bloqueado="nueva.guardando"
      @cerrar="nueva.abierto = false"
    >
      <template #cabecera>
        <div class="relative -mx-6 -mt-6 mb-4 flex items-center gap-3 overflow-hidden rounded-t-2xl px-6 py-4" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <svg class="pointer-events-none absolute -top-2 right-12 h-24 w-20 rotate-[8deg]" viewBox="0 0 80 100" fill="rgb(255 255 255 / 0.07)" stroke="rgb(255 255 255 / 0.28)" stroke-linejoin="round" aria-hidden="true"><path d="M8 0h64v92l-8-6-8 6-8-6-8 6-8-6-8 6-8-6-8 6z" /></svg>
          <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/25 bg-blanco/15 text-white" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6m-6 4h6m-6 4h3M5 3h14v18l-2.300-1.500L14.300 21 12 19.500 9.700 21l-2.400-1.500L5 21z" /></svg>
          </span>
          <div class="relative min-w-0 flex-1">
            <h3 class="font-serif text-lg font-bold text-white">Cursar una multa</h3>
            <p class="text-xs text-white/80">Para deterioro, pérdida de material u otro motivo</p>
          </div>
          <button type="button" class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-colors hover:bg-blanco/15 disabled:opacity-50" aria-label="Cerrar" :disabled="nueva.guardando" @click="nueva.abierto = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-dorado-700">Las multas por atraso se generan solas al devolver.</p>
      </template>
      <div class="space-y-3">
        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-800" for="multa-rut">
              <span class="flex h-5 w-5 items-center justify-center rounded-md bg-indigo-600 text-[11px] font-bold text-white">1</span>
              {{ modoRutMulta === 'rut' ? 'RUT del usuario' : 'Pasaporte o DNI del usuario' }}
            </label>
            <SelectorDocumento v-model="modoRutMulta" compacto />
          </div>
          <div class="flex gap-2">
            <input
              id="multa-rut"
              :value="nueva.rut"
              :placeholder="placeholderSegunModo(modoRutMulta)"
              :maxlength="largoSegunModo(modoRutMulta)"
              class="flex-1 min-w-0 px-3 py-2 bg-white border border-gray-200 rounded-lg font-mono text-sm focus:ring-2 focus:ring-indigo-500 outline-none"
              @input="escribirRutMulta($event); nueva.usuario = null; nueva.errorRut = ''"
              @keydown.enter.prevent="buscarUsuarioMulta"
            />
            <button @click="buscarUsuarioMulta" :disabled="nueva.buscando" class="px-4 py-2 border border-gray-200 border-b-indigo-200 bg-white rounded-lg text-indigo-700 shadow-sm hover:bg-gray-50 transition-colors font-semibold text-sm">
              {{ nueva.buscando ? 'Buscando…' : 'Buscar' }}
            </button>
          </div>
          <p v-if="nueva.errorRut" class="text-xs text-red-600 mt-1">{{ nueva.errorRut }}</p>
          <p v-if="nueva.usuario" class="mt-2 text-sm bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg px-3 py-2">
            {{ nueva.usuario.nombre }} {{ nueva.usuario.apellido }} · {{ nueva.usuario.carrera ?? nueva.usuario.tipo }}
          </p>
        </div>
        <div class="space-y-3 rounded-xl border border-gray-100 bg-gray-50 p-3">
        <p class="flex items-center gap-2 text-sm font-semibold text-gray-800">
          <span class="flex h-5 w-5 items-center justify-center rounded-md bg-indigo-600 text-[11px] font-bold text-white">2</span>
          Detalle de la multa
        </p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-tipo">Motivo</label>
            <select id="multa-tipo" v-model="nueva.tipo" :disabled="!nueva.usuario" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm disabled:bg-gray-50">
              <option value="deterioro">Deterioro</option>
              <option value="perdida">Pérdida</option>
              <option value="otro">Otro motivo</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-monto">Monto</label>
            <div class="flex items-center overflow-hidden rounded-lg border border-gray-200 bg-white focus-within:ring-2 focus-within:ring-indigo-500">
              <span class="border-r border-gray-200 bg-gray-50 px-2.5 py-2 text-sm font-semibold text-gray-500" aria-hidden="true">$</span>
              <input id="multa-monto" v-model.number="nueva.monto" :disabled="!nueva.usuario" type="number" min="1" step="1" placeholder="25000" class="w-full min-w-0 border-0 bg-transparent px-2.5 py-2 text-sm outline-none" />
              <span class="border-l border-gray-200 bg-gray-50 px-2 py-2 text-xs font-semibold text-gray-500">CLP</span>
            </div>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-detalle">Descripción</label>
          <textarea
            id="multa-detalle"
            v-model="nueva.detalle"
            :disabled="!nueva.usuario"
            rows="3"
            maxlength="500"
            placeholder="Ej: Pérdida del libro «Cálculo de una variable»"
            class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm disabled:bg-gray-50"
          />
        </div>
        </div>
      </div>
      <template #pie>
        <button @click="nueva.abierto = false" :disabled="nueva.guardando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button
          @click="pedirConfirmacionNueva"
          :disabled="nueva.guardando || !nueva.usuario"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
        >
          Cursar multa
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="confirmandoExportar"
      :titulo="pestana === 'deudas' ? '¿Exportar la deuda por usuario?' : '¿Exportar las multas?'"
      confirmar="Confirmar y exportar"
      tono="exito"
      :cargando="exportando"
      @confirmar="exportarExcel"
      @cancelar="confirmandoExportar = false"
    >
      <p v-if="pestana === 'deudas'">
        Se descargará en Excel (.xlsx) la deuda pendiente de {{ totalAExportar.toLocaleString('es-CL') }}
        {{ totalAExportar === 1 ? 'persona' : 'personas' }}<template v-if="busquedaDeudas.trim()"> que coinciden con la búsqueda</template>.
      </p>
      <p v-else>
        Se descargarán en Excel (.xlsx) {{ totalAExportar.toLocaleString('es-CL') }} {{ totalAExportar === 1 ? 'multa' : 'multas' }}
        ({{ filtros.estado ? ESTADOS[filtros.estado].label.toLowerCase() + 's' : 'todos los estados' }}<template v-if="filtros.tipo">, por {{ TIPOS[filtros.tipo].toLowerCase() }}</template>),
        todas las páginas, con su motivo, detalle, préstamo y quién la cobró o anuló.
      </p>
      <p v-if="exportando && progreso" class="mt-2 font-medium text-gray-700">{{ progreso }}</p>
      <p class="mt-2">El archivo incluye nombres, RUT y correos: guárdalo solo donde corresponda.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="nueva.confirmando"
      titulo="¿Cursar esta multa?"
      confirmar="Sí, cursar multa"
      tono="peligro"
      :cargando="nueva.guardando"
      @confirmar="guardarNueva"
      @cancelar="nueva.confirmando = false"
    >
      <p>
        Se cursará una multa de <strong class="text-gray-900">{{ formatMonto(nueva.monto ?? 0) }}</strong> por {{ TIPOS[nueva.tipo].toLowerCase() }} a
        <strong class="text-gray-900">{{ nueva.usuario?.nombre }} {{ nueva.usuario?.apellido }}</strong>: «{{ nueva.detalle.trim() }}».
      </p>
      <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Mientras no la pague o se anule, no podrá pedir préstamos ni hacer reservas.
      </p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
