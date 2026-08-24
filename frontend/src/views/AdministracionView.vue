<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import JsBarcode from 'jsbarcode'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { ConfiguracionInstitucional, EstadoLibroPersonalizado, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()
const apiError = ref(false)
const cargando = ref(true)

const configuracion = reactive({ jefe_unidad_nombre: '', jefe_unidad_cargo: '' })
const configuracionOriginal = reactive({ jefe_unidad_nombre: '', jefe_unidad_cargo: '' })
const guardandoConfig = ref(false)
const confirmandoConfig = ref(false)

const estados = ref<EstadoLibroPersonalizado[]>([])
const nuevoEstado = reactive({ nombre: '', descripcion: '' })
const creandoEstado = ref(false)
const confirmandoCrearEstado = ref(false)
const estadoADesactivar = ref<EstadoLibroPersonalizado | null>(null)
const desactivandoEstado = ref(false)

const ubicaciones = ref<Ubicacion[]>([])
const nuevaUbicacion = reactive({ nombre: '' })
const creandoUbicacion = ref(false)
const confirmandoCrearUbicacion = ref(false)

const tiposMaterial = ref<TipoMaterial[]>([])
const nuevoTipoMaterial = reactive({ nombre: '' })
const creandoTipoMaterial = ref(false)
const confirmandoCrearTipoMaterial = ref(false)

const codigosTexto = ref('')
// Los códigos reales de la biblioteca son numéricos de 14 dígitos (ej. 30000003227565,
// formato heredado de Horizon) — sin prefijo de letras.
const rango = reactive({ prefijo: '', desde: 1, cantidad: 10, digitos: 14 })
const sugiriendoRango = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const [configRes, estadosRes, ubicacionesRes, tiposMaterialRes] = await Promise.all([
      api.get<ConfiguracionInstitucional>('/configuracion'),
      api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados'),
      api.get<Ubicacion[]>('/ubicaciones'),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    Object.assign(configuracion, configRes.data)
    Object.assign(configuracionOriginal, configRes.data)
    estados.value = estadosRes.data
    ubicaciones.value = ubicacionesRes.data
    tiposMaterial.value = tiposMaterialRes.data
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

function pedirConfirmacionConfig() {
  if (!configuracion.jefe_unidad_nombre.trim() || !configuracion.jefe_unidad_cargo.trim()) {
    toast.error('Completa nombre y cargo')
    return
  }
  confirmandoConfig.value = true
}

async function guardarConfiguracion() {
  guardandoConfig.value = true
  try {
    await api.put('/configuracion', configuracion)
    Object.assign(configuracionOriginal, configuracion)
    toast.success('Configuración institucional actualizada')
    confirmandoConfig.value = false
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo guardar')
  } finally {
    guardandoConfig.value = false
  }
}

function pedirConfirmacionCrearEstado() {
  if (!nuevoEstado.nombre.trim()) {
    toast.error('Ingresa un nombre para el estado personalizado')
    return
  }
  confirmandoCrearEstado.value = true
}

async function crearEstado() {
  creandoEstado.value = true
  try {
    const { data } = await api.post<EstadoLibroPersonalizado>('/estados-libro-personalizados', {
      nombre: nuevoEstado.nombre.trim(),
      descripcion: nuevoEstado.descripcion.trim() || null,
    })
    estados.value.push(data)
    nuevoEstado.nombre = ''
    nuevoEstado.descripcion = ''
    confirmandoCrearEstado.value = false
    toast.success('Estado personalizado creado')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo crear el estado')
  } finally {
    creandoEstado.value = false
  }
}

function pedirConfirmacionCrearUbicacion() {
  if (!nuevaUbicacion.nombre.trim()) {
    toast.error('Ingresa un nombre para la ubicación')
    return
  }
  confirmandoCrearUbicacion.value = true
}

async function crearUbicacion() {
  creandoUbicacion.value = true
  try {
    const { data } = await api.post<Ubicacion>('/ubicaciones', { nombre: nuevaUbicacion.nombre.trim() })
    ubicaciones.value.push(data)
    ubicaciones.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    nuevaUbicacion.nombre = ''
    confirmandoCrearUbicacion.value = false
    toast.success('Ubicación creada')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo crear la ubicación')
  } finally {
    creandoUbicacion.value = false
  }
}

function pedirConfirmacionCrearTipoMaterial() {
  if (!nuevoTipoMaterial.nombre.trim()) {
    toast.error('Ingresa un nombre para el tipo de material')
    return
  }
  confirmandoCrearTipoMaterial.value = true
}

async function crearTipoMaterial() {
  creandoTipoMaterial.value = true
  try {
    const { data } = await api.post<TipoMaterial>('/tipos-material', { nombre: nuevoTipoMaterial.nombre.trim() })
    tiposMaterial.value.push(data)
    tiposMaterial.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    nuevoTipoMaterial.nombre = ''
    confirmandoCrearTipoMaterial.value = false
    toast.success('Tipo de material creado')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo crear el tipo de material')
  } finally {
    creandoTipoMaterial.value = false
  }
}

async function sugerirSiguienteCodigo() {
  sugiriendoRango.value = true
  try {
    const { data } = await api.get<{ codigo_barras: string }>('/ejemplares/siguiente-codigo-barras')
    const match = data.codigo_barras.match(/^([A-Za-z]*)(\d+)$/)
    if (match) {
      rango.prefijo = match[1]
      rango.desde = parseInt(match[2], 10)
      rango.digitos = match[2].length
    }
    toast.success(`Sugerido: ${data.codigo_barras}`)
  } catch {
    toast.error('No se pudo obtener el siguiente código sugerido')
  } finally {
    sugiriendoRango.value = false
  }
}

function generarRango() {
  if (!rango.cantidad || rango.cantidad < 1 || rango.cantidad > 500) {
    toast.error('La cantidad debe ser entre 1 y 500')
    return
  }
  const codigos: string[] = []
  for (let i = 0; i < rango.cantidad; i++) {
    const numero = (rango.desde + i).toString().padStart(rango.digitos, '0')
    codigos.push(`${rango.prefijo}${numero}`)
  }
  codigosTexto.value = codigos.join('\n')
}

function escapeHtml(texto: string): string {
  return texto
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function generarBarcodeDataUrl(codigo: string): string {
  const canvas = document.createElement('canvas')
  JsBarcode(canvas, codigo, {
    format: 'CODE128',
    width: 2,
    height: 60,
    displayValue: true,
    fontSize: 14,
    margin: 8,
  })
  return canvas.toDataURL('image/png')
}

// Abre una pestaña nueva con las etiquetas ya listas para Ctrl+P — no depende del
// backend ni escribe nada, es puramente un utilitario de impresión sobre códigos
// que el staff ya tiene (recién catalogados o para reimprimir una etiqueta perdida).
function abrirImpresion() {
  const codigos = codigosTexto.value
    .split('\n')
    .map((c) => c.trim())
    .filter((c) => c.length > 0)

  if (!codigos.length) {
    toast.error('Ingresa al menos un código para generar')
    return
  }

  const etiquetas = codigos
    .map((codigo) => {
      try {
        const img = generarBarcodeDataUrl(codigo)
        return `<div class="etiqueta"><img src="${img}" alt="${escapeHtml(codigo)}" /></div>`
      } catch {
        return `<div class="etiqueta etiqueta-error">Código inválido:<br>${escapeHtml(codigo)}</div>`
      }
    })
    .join('')

  const ventana = window.open('', '_blank')
  if (!ventana) {
    toast.error('El navegador bloqueó la ventana nueva — habilita las ventanas emergentes para este sitio')
    return
  }

  ventana.document.write(`<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Códigos de barra — Biblioteca UMAG</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 24px; background: #f4f6f4; }
  .toolbar { margin-bottom: 20px; }
  .toolbar button {
    padding: 10px 20px; border-radius: 8px; border: none;
    background: #3c5a3b; color: white; font-weight: 600; cursor: pointer; font-size: 14px;
  }
  .toolbar button:hover { background: #31482f; }
  .hoja { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .etiqueta {
    background: white; border: 1px solid #c9d4c8; border-radius: 8px;
    padding: 10px; display: flex; align-items: center; justify-content: center; min-height: 90px;
  }
  .etiqueta img { max-width: 100%; }
  .etiqueta-error { color: #b91c1c; font-size: 12px; text-align: center; }
  @media print {
    .toolbar { display: none; }
    body { background: white; padding: 0; }
    .hoja { gap: 6px; }
    .etiqueta { border: 1px dashed #999; break-inside: avoid; }
  }
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Imprimir</button></div>
  <div class="hoja">${etiquetas}</div>
</body>
</html>`)
  ventana.document.close()
}

async function aplicarCambioActivo(estado: EstadoLibroPersonalizado, activo: boolean) {
  try {
    const { data } = await api.patch<EstadoLibroPersonalizado>(`/estados-libro-personalizados/${estado.id}/activo`, { activo })
    const idx = estados.value.findIndex((e) => e.id === estado.id)
    if (idx !== -1) estados.value[idx] = data
  } catch {
    toast.error('No se pudo cambiar el estado')
  }
}

// Desactivar pide confirmación (deja de ofrecerse para libros nuevos); reactivar es
// la dirección "segura" y no la necesita.
function toggleActivo(estado: EstadoLibroPersonalizado) {
  if (estado.activo) {
    estadoADesactivar.value = estado
    return
  }
  aplicarCambioActivo(estado, true)
}

async function confirmarDesactivar() {
  if (!estadoADesactivar.value) return
  desactivandoEstado.value = true
  try {
    await aplicarCambioActivo(estadoADesactivar.value, false)
    estadoADesactivar.value = null
  } finally {
    desactivandoEstado.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Administración</h1>
          <p class="text-sm text-white/60 mt-1">Configuración institucional y catálogos administrables</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div v-if="!cargando" class="space-y-6">
        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Configuración institucional</h3>
          <p class="text-xs text-gray-500 mb-4">
            Nombre y cargo de quien firma la Constancia de No Multa (Usuarios → Constancia).
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
              <input v-model="configuracion.jefe_unidad_nombre" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Cargo</label>
              <input v-model="configuracion.jefe_unidad_cargo" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
          </div>
          <button
            @click="pedirConfirmacionConfig"
            :disabled="guardandoConfig"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            {{ guardandoConfig ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Estados personalizados de libro</h3>
          <p class="text-xs text-gray-500 mb-4">
            Aparecen como opción "Personalizado" en Estado de Libro y Cambio Masivo. Un estado ya usado no se puede
            borrar, solo desactivar (deja de ofrecerse en el selector).
          </p>

          <div class="flex flex-col sm:flex-row gap-2 mb-5">
            <input v-model="nuevoEstado.nombre" placeholder="Nombre (ej: Restauración)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            <input v-model="nuevoEstado.descripcion" placeholder="Descripción (opcional)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            <button
              @click="pedirConfirmacionCrearEstado"
              :disabled="creandoEstado"
              class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
            >
              + Crear
            </button>
          </div>

          <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
            <div v-for="e in estados" :key="e.id" class="flex items-center justify-between px-4 py-3">
              <div>
                <p class="text-sm font-medium text-gray-900">{{ e.nombre }}</p>
                <p v-if="e.descripcion" class="text-xs text-gray-500">{{ e.descripcion }}</p>
              </div>
              <button
                @click="toggleActivo(e)"
                class="text-xs px-3 py-1 rounded-full font-medium transition-colors"
                :class="e.activo ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
              >
                {{ e.activo ? 'Activo' : 'Inactivo' }}
              </button>
            </div>
            <p v-if="!estados.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin estados personalizados todavía.</p>
          </div>
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Ubicaciones</h3>
          <p class="text-xs text-gray-500 mb-4">
            Sedes y otras ubicaciones físicas donde puede estar un ejemplar — se elige al catalogar un libro
            y sirve para filtrar en Cambio Masivo de Estado. Agrega acá cualquier sede nueva, no hay límite.
          </p>

          <div class="flex flex-col sm:flex-row gap-2 mb-5">
            <input v-model="nuevaUbicacion.nombre" placeholder="Nombre (ej: Biblioteca Central, Sede Puerto Natales)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" @keydown.enter="pedirConfirmacionCrearUbicacion" />
            <button
              @click="pedirConfirmacionCrearUbicacion"
              :disabled="creandoUbicacion"
              class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
            >
              + Crear
            </button>
          </div>

          <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
            <div v-for="u in ubicaciones" :key="u.id" class="px-4 py-3">
              <p class="text-sm font-medium text-gray-900">{{ u.nombre }}</p>
            </div>
            <p v-if="!ubicaciones.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin ubicaciones todavía.</p>
          </div>
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Tipos de material</h3>
          <p class="text-xs text-gray-500 mb-4">
            Se eligen al catalogar un libro y sirven para filtrar en Listado de Libros y Cambio Masivo de Estado.
            Agrega acá cualquier tipo nuevo (ej: Mapa, Partitura), no hay límite.
          </p>

          <div class="flex flex-col sm:flex-row gap-2 mb-5">
            <input v-model="nuevoTipoMaterial.nombre" placeholder="Nombre (ej: Mapa)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" @keydown.enter="pedirConfirmacionCrearTipoMaterial" />
            <button
              @click="pedirConfirmacionCrearTipoMaterial"
              :disabled="creandoTipoMaterial"
              class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
            >
              + Crear
            </button>
          </div>

          <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
            <div v-for="t in tiposMaterial" :key="t.id" class="px-4 py-3">
              <p class="text-sm font-medium text-gray-900">{{ t.nombre }}</p>
            </div>
            <p v-if="!tiposMaterial.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin tipos de material todavía.</p>
          </div>
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Códigos de barra para imprimir</h3>
          <p class="text-xs text-gray-500 mb-4">
            Genera etiquetas con código de barra real (Code128) a partir de una lista de códigos — sirve para
            libros, equipos o cualquier código ya asignado en el sistema. Se abren en una pestaña nueva lista
            para imprimir.
          </p>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Prefijo</label>
              <input v-model="rango.prefijo" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Desde</label>
              <input v-model.number="rango.desde" type="number" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Cantidad</label>
              <input v-model.number="rango.cantidad" type="number" min="1" max="500" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Dígitos</label>
              <input v-model.number="rango.digitos" type="number" min="1" max="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
          </div>

          <div class="flex flex-wrap gap-2 mb-4">
            <button
              @click="sugerirSiguienteCodigo"
              :disabled="sugiriendoRango"
              class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium text-xs disabled:opacity-60"
            >
              {{ sugiriendoRango ? 'Consultando…' : 'Sugerir siguiente (libros)' }}
            </button>
            <button
              @click="generarRango"
              class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium text-xs"
            >
              Generar lista con este rango
            </button>
          </div>

          <label class="block text-xs font-medium text-gray-600 mb-1">Códigos a imprimir (uno por línea)</label>
          <textarea
            v-model="codigosTexto"
            rows="6"
            placeholder="30000003227565&#10;30000003227566&#10;30000003227567"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono mb-4"
          />

          <button
            @click="abrirImpresion"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm"
          >
            Generar e imprimir
          </button>
        </div>
      </div>

      <div
        v-if="confirmandoConfig"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoConfig = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Confirmar cambio de datos institucionales?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Este nombre y cargo aparecen firmando la Constancia de No Multa que se le entrega a los usuarios.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6 space-y-1">
            <p v-if="configuracionOriginal.jefe_unidad_nombre !== configuracion.jefe_unidad_nombre">
              Nombre: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_nombre }}</span>
              → <strong>{{ configuracion.jefe_unidad_nombre }}</strong>
            </p>
            <p v-if="configuracionOriginal.jefe_unidad_cargo !== configuracion.jefe_unidad_cargo">
              Cargo: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_cargo }}</span>
              → <strong>{{ configuracion.jefe_unidad_cargo }}</strong>
            </p>
            <p v-if="configuracionOriginal.jefe_unidad_nombre === configuracion.jefe_unidad_nombre && configuracionOriginal.jefe_unidad_cargo === configuracion.jefe_unidad_cargo" class="text-gray-500">
              Sin cambios respecto a lo guardado actualmente.
            </p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoConfig = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="guardarConfiguracion"
              :disabled="guardandoConfig"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ guardandoConfig ? 'Guardando…' : 'Sí, guardar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCrearEstado"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearEstado = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear este estado personalizado?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible como opción "Personalizado" en Estado de Libro y Cambio Masivo para todo el staff.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6 space-y-1">
            <p><strong>{{ nuevoEstado.nombre }}</strong></p>
            <p v-if="nuevoEstado.descripcion" class="text-gray-500">{{ nuevoEstado.descripcion }}</p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearEstado = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearEstado"
              :disabled="creandoEstado"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoEstado ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCrearUbicacion"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearUbicacion = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear esta ubicación?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible para elegir al catalogar un libro y como filtro en Cambio Masivo de Estado.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6">
            <p><strong>{{ nuevaUbicacion.nombre }}</strong></p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearUbicacion = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearUbicacion"
              :disabled="creandoUbicacion"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoUbicacion ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCrearTipoMaterial"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearTipoMaterial = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear este tipo de material?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible para elegir al catalogar un libro y como filtro en Listado de Libros y Cambio Masivo de Estado.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6">
            <p><strong>{{ nuevoTipoMaterial.nombre }}</strong></p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearTipoMaterial = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearTipoMaterial"
              :disabled="creandoTipoMaterial"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoTipoMaterial ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="estadoADesactivar"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="estadoADesactivar = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Desactivar este estado?</h3>
          <p class="text-sm text-gray-500 mb-6">
            <strong>{{ estadoADesactivar.nombre }}</strong> dejará de aparecer como opción en Estado de Libro y Cambio
            Masivo. Los ejemplares que ya lo tienen asignado no se ven afectados, y se puede reactivar cuando quieras.
          </p>
          <div class="flex gap-3">
            <button
              @click="estadoADesactivar = null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarDesactivar"
              :disabled="desactivandoEstado"
              class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ desactivandoEstado ? 'Desactivando…' : 'Sí, desactivar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
