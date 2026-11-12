<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import JsBarcode from 'jsbarcode'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import EtiquetasLomo from '@/components/libros/EtiquetasLomo.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const route = useRoute()
const router = useRouter()
const guiaAbierta = ref(false)

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

// Dos tipos de etiqueta: el código de barras de siempre (14 dígitos, horizontal) y la de
// lomo (clasificación + código de 8 dígitos parado), para pegar en el lomo del libro.
type Pestana = 'codigos' | 'lomo'
const pestana = ref<Pestana>(route.query.lomo !== undefined || route.query.pestana === 'lomo' ? 'lomo' : 'codigos')

function elegirPestana(p: Pestana) {
  pestana.value = p
  router.replace({ query: { ...route.query, pestana: p } })
}

// Va impreso bajo los números de cada etiqueta, como en las etiquetas de la biblioteca.
const INSTITUCION = 'UNIVERSIDAD DE MAGALLANES'

const codigosTexto = ref('')
// Los códigos reales de la biblioteca son numéricos de 14 dígitos (ej. 30000003227565,
// formato heredado de Horizon) — sin prefijo de letras.
const rango = reactive({ prefijo: '', desde: 1, cantidad: 10, digitos: 14, formato: 'largo' as 'largo' | 'corto' })

/**
 * Largo: los 14 dígitos de Horizon (30000003222799). Corto: los 8 que se escriben a mano
 * (03222799), que el sistema reconoce igual: el largo es «3» + el corto con ceros a la izquierda.
 */
const EJEMPLO_LARGO = ['30000003227565', '30000003227566', '30000003227567'].join('\n')
const EJEMPLO_CORTO = ['03227565', '03227566', '03227567'].join('\n')

function elegirFormato(formato: 'largo' | 'corto') {
  rango.formato = formato
  rango.prefijo = ''
  rango.digitos = formato === 'corto' ? 8 : 14
  // El mismo número en el otro formato: 30000003222799 ⇄ 03222799
  const texto = String(rango.desde)
  if (formato === 'corto' && texto.length === 14 && texto.startsWith('3')) rango.desde = parseInt(texto.slice(1), 10)
  if (formato === 'largo' && rango.desde > 0 && texto.length <= 8) rango.desde = parseInt(`3${texto.padStart(13, '0')}`, 10)
}
const sugiriendoRango = ref(false)

async function sugerirSiguienteCodigo() {
  sugiriendoRango.value = true
  try {
    const { data } = await api.get<{ codigo_barras: string }>('/ejemplares/siguiente-codigo-barras')
    const match = data.codigo_barras.match(/^([A-Za-z]*)(\d+)$/)
    let sugerido = data.codigo_barras
    if (match && rango.formato === 'corto' && match[2].length === 14 && match[2].startsWith('3')) {
      // El corto del siguiente libro: sin el «3» y los ceros de relleno, en 8 dígitos
      rango.prefijo = ''
      rango.desde = parseInt(match[2].slice(1), 10)
      rango.digitos = 8
      sugerido = String(rango.desde).padStart(8, '0')
    } else if (match) {
      rango.prefijo = match[1]
      rango.desde = parseInt(match[2], 10)
      rango.digitos = match[2].length
    }
    toast.success(`Sugerido: ${sugerido}`)
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
    fontOptions: 'bold',
    margin: 8,
    // El nombre de la institución va justo debajo, como texto (se imprime más nítido).
    marginBottom: 2,
  })
  return canvas.toDataURL('image/png')
}

// Abre una pestaña nueva con las etiquetas ya listas para Ctrl+P — no depende del
// backend ni escribe nada, es puramente un utilitario de impresión sobre códigos
// que el staff ya tiene (recién catalogados o para reimprimir una etiqueta perdida).
const cantidadCodigos = computed(() => codigosTexto.value.split('\n').filter((c) => c.trim()).length)

// La etiqueta de muestra se genera igual que la impresa, con el primer código de la lista.
const MUESTRA = '30000003227565'
const muestraUrl = computed(() => {
  const primero = codigosTexto.value.split('\n').map((c) => c.trim()).find(Boolean)
  try {
    return generarBarcodeDataUrl(primero ?? MUESTRA)
  } catch {
    return generarBarcodeDataUrl(MUESTRA)
  }
})

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
        return `<div class="etiqueta"><img src="${img}" alt="${escapeHtml(codigo)}" /><span class="institucion">${INSTITUCION}</span></div>`
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
  body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 24px; background: #f8fafc; }
  .toolbar { margin-bottom: 20px; }
  .toolbar button {
    padding: 10px 20px; border-radius: 8px; border: none;
    background: #4338ca; color: white; font-weight: 600; cursor: pointer; font-size: 14px;
  }
  .toolbar button:hover { background: #3730a3; }
  .hoja { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .etiqueta {
    background: white; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 90px;
  }
  .etiqueta img { max-width: 100%; }
  .institucion {
    margin-top: 1px; font-family: "Arial Black", "Segoe UI Black", system-ui, sans-serif;
    font-size: 10.5px; font-weight: 900; color: #000; text-align: center; white-space: nowrap;
  }
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
  <div class="toolbar"><button type="button">Imprimir</button></div>
  <div class="hoja">${etiquetas}</div>
</body>
</html>`)
  ventana.document.close()
  // El botón se conecta desde acá: la política de seguridad del sitio no deja correr
  // código escrito dentro de la página (un onclick="...").
  ventana.document.querySelector('.toolbar button')?.addEventListener('click', () => ventana.print())
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-6xl">
      <!-- La caja de título con la textura de Salas y Préstamos. -->
      <div class="relative mb-5 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <!-- Textura propia: una plancha de etiquetas adhesivas, desvanecida hacia la izquierda, con una etiqueta suelta. -->
        <svg class="pointer-events-none absolute inset-0 h-full w-full" style="-webkit-mask-image: linear-gradient(to right, transparent 35%, black 75%); mask-image: linear-gradient(to right, transparent 35%, black 75%);" aria-hidden="true">
          <defs>
            <pattern id="hoja-etiquetas" width="86" height="46" patternUnits="userSpaceOnUse" patternTransform="rotate(-6)">
              <rect x="4" y="4" width="78" height="38" rx="6" fill="rgb(255 255 255 / 0.05)" stroke="rgb(255 255 255 / 0.18)" stroke-dasharray="4 3" />
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#hoja-etiquetas)" />
        </svg>
        <svg class="pointer-events-none absolute -top-3 right-64 hidden h-28 w-24 -rotate-[24deg] sm:block" viewBox="0 0 100 130" fill="rgb(255 255 255 / 0.05)" stroke="rgb(255 255 255 / 0.28)" stroke-width="4" aria-hidden="true">
          <path stroke-linejoin="round" d="M50 6 90 40v78a6 6 0 0 1-6 6H16a6 6 0 0 1-6-6V40z" />
          <circle cx="50" cy="40" r="9" />
        </svg>
        <div class="relative flex flex-wrap items-center justify-between gap-3 px-6 py-4">
          <div class="min-w-0">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Catálogo</span>
            </nav>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Etiquetas de Código</h1>
            <p class="mt-0.5 text-sm text-white/75">Códigos de barra Code128 y etiquetas de lomo listos para imprimir</p>
          </div>
          <button
            type="button"
            class="inline-flex shrink-0 items-center gap-2 rounded-full border border-white/30 bg-blanco/10 px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blanco/20"
            :aria-expanded="guiaAbierta"
            @click="guiaAbierta = !guiaAbierta"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
            </svg>
            {{ guiaAbierta ? 'Ocultar la ayuda' : '¿Cómo se usa esta pantalla?' }}
          </button>
        </div>
      </div>

      <!-- Qué tipo de etiqueta: dos tarjetas con forma de ticket. -->
      <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2" role="tablist" aria-label="Tipo de etiqueta">
        <button
          type="button"
          role="tab"
          :aria-selected="pestana === 'codigos'"
          class="relative flex items-center gap-4 rounded-2xl border-2 bg-white py-2.5 pl-5 pr-5 text-left shadow-sm transition-colors"
          :class="pestana === 'codigos' ? 'border-indigo-600 bg-indigo-50/40' : 'border-dashed border-gray-300 hover:border-gray-400'"
          @click="elegirPestana('codigos')"
        >
          <span class="absolute left-0 top-1/2 h-4 w-2 -translate-y-1/2 rounded-r-full border-2 border-l-0" :class="pestana === 'codigos' ? 'border-indigo-600' : 'border-dashed border-gray-300'" aria-hidden="true"></span>
          <span class="absolute right-0 top-1/2 h-4 w-2 -translate-y-1/2 rounded-l-full border-2 border-r-0" :class="pestana === 'codigos' ? 'border-indigo-600' : 'border-dashed border-gray-300'" aria-hidden="true"></span>
          <span class="tema-claro flex h-11 w-16 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white shadow-sm" aria-hidden="true">
            <span class="h-6 w-11" style="background: repeating-linear-gradient(90deg, #111 0 2px, transparent 2px 3px, #111 3px 4px, transparent 4px 6px);"></span>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold" :class="pestana === 'codigos' ? 'text-indigo-700' : 'text-gray-900'">Códigos de barra</span>
            <span class="block text-xs text-gray-500">Code128 para libros y equipos</span>
          </span>
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="pestana === 'codigos' ? 'bg-indigo-600 text-white' : 'border-2 border-gray-300'" aria-hidden="true">
            <svg v-if="pestana === 'codigos'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          </span>
        </button>

        <button
          type="button"
          role="tab"
          :aria-selected="pestana === 'lomo'"
          class="relative flex items-center gap-4 rounded-2xl border-2 bg-white py-2.5 pl-5 pr-5 text-left shadow-sm transition-colors"
          :class="pestana === 'lomo' ? 'border-[#b08d57] bg-amber-50/60' : 'border-dashed border-gray-300 hover:border-gray-400'"
          @click="elegirPestana('lomo')"
        >
          <span class="absolute left-0 top-1/2 h-4 w-2 -translate-y-1/2 rounded-r-full border-2 border-l-0" :class="pestana === 'lomo' ? 'border-[#b08d57]' : 'border-dashed border-gray-300'" aria-hidden="true"></span>
          <span class="absolute right-0 top-1/2 h-4 w-2 -translate-y-1/2 rounded-l-full border-2 border-r-0" :class="pestana === 'lomo' ? 'border-[#b08d57]' : 'border-dashed border-gray-300'" aria-hidden="true"></span>
          <span class="tema-claro flex h-11 w-16 shrink-0 items-center justify-between rounded-lg border border-gray-200 bg-white px-2 shadow-sm" aria-hidden="true">
            <span class="flex flex-col gap-1">
              <span class="block h-[3px] w-6 rounded-full bg-gray-800"></span>
              <span class="block h-[3px] w-5 rounded-full bg-gray-800"></span>
              <span class="block h-[3px] w-4 rounded-full bg-gray-800"></span>
            </span>
            <span class="h-7 w-3" style="background: repeating-linear-gradient(0deg, #111 0 2px, transparent 2px 3px);"></span>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold" :class="pestana === 'lomo' ? 'text-dorado-700' : 'text-gray-900'">Etiquetas de lomo</span>
            <span class="block text-xs text-gray-500">Clasificación y código de la copia</span>
          </span>
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="pestana === 'lomo' ? 'bg-[#b08d57] text-white' : 'border-2 border-gray-300'" aria-hidden="true">
            <svg v-if="pestana === 'lomo'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          </span>
        </button>
      </div>

      <template v-if="pestana === 'lomo'">
        <GuiaUso
          v-model:abierta="guiaAbierta"
          :con-boton="false"
          :pasos="[
            'Agregue las copias: escanee o escriba sus códigos (el de 14 dígitos o el de lomo de 8), o busque el título y pulse <strong>Agregar</strong> para todas sus copias.',
            'Revise la <strong>clasificación</strong> de cada una: cada palabra va en una línea (998 / E56 / 2026). Si falta, escríbala ahí mismo.',
            'Ajuste el tamaño de la etiqueta si es necesario y pulse <strong>Generar e imprimir</strong>. Imprima al 100 %.',
          ]"
          consejo="El código de lomo lo asigna el sistema a cada copia y el lector lo reconoce en Préstamos y en Estado de ejemplares, igual que el de 14 dígitos."
        />
        <EtiquetasLomo />
      </template>

      <template v-else>
        <GuiaUso
          v-model:abierta="guiaAbierta"
          :con-boton="false"
          :pasos="[
            'Pulse <strong>Sugerir siguiente (libros)</strong> para partir desde el primer código de libro que todavía no se usa.',
            'Indique la <strong>cantidad</strong> y pulse <strong>Generar lista con este rango</strong>, o pegue códigos a mano (uno por línea).',
            'Pulse <strong>Generar e imprimir</strong>: se abre una pestaña con las etiquetas y su botón Imprimir.',
          ]"
          consejo="Imprimir etiquetas no registra nada: los códigos se asignan al catalogar cada copia."
        />

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
          <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
            <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3.5">
              <div class="border-l-[3px] border-indigo-600 pl-3">
                <h3 class="font-serif text-base font-bold text-gray-900">Códigos de barra para imprimir</h3>
                <p class="mt-0.5 text-xs text-gray-500">
                  Genera etiquetas con código de barra real (Code128) a partir de una lista de códigos: sirve para libros, equipos o cualquier
                  código ya asignado en el sistema. Se abren en una pestaña nueva lista para imprimir.
                </p>
              </div>
            </header>

            <div class="space-y-4 p-5">
              <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                <p class="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700">
                  <span class="flex h-5 w-5 items-center justify-center rounded-md bg-indigo-600 text-[11px] text-white">1</span>
                  Armar un rango
                </p>
                <div class="mb-3 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Formato del código">
                  <span class="text-xs font-medium text-gray-700">Formato</span>
                  <button
                    v-for="f in ([['largo', 'Largo · 14 dígitos', '30000003222799'], ['corto', 'Corto · 8 dígitos', '03222799']] as const)"
                    :key="f[0]"
                    type="button"
                    role="radio"
                    :aria-checked="rango.formato === f[0]"
                    class="rounded-lg border px-3 py-1.5 text-left text-xs transition-colors"
                    :class="rango.formato === f[0] ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                    @click="elegirFormato(f[0])"
                  >
                    <span class="block font-semibold">{{ f[1] }}</span>
                    <span class="block font-mono opacity-80">{{ f[2] }}</span>
                  </button>
                </div>
                <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                  <div>
                    <label for="eti-prefijo" class="mb-1 block text-xs font-medium text-gray-700">Prefijo</label>
                    <input id="eti-prefijo" v-model="rango.prefijo" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                  </div>
                  <div>
                    <label for="eti-desde" class="mb-1 block text-xs font-medium text-gray-700">Desde</label>
                    <input id="eti-desde" v-model.number="rango.desde" type="number" min="0" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                  </div>
                  <div>
                    <label for="eti-cantidad" class="mb-1 block text-xs font-medium text-gray-700">Cantidad</label>
                    <input id="eti-cantidad" v-model.number="rango.cantidad" type="number" min="1" max="500" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                  </div>
                  <div>
                    <label for="eti-digitos" class="mb-1 block text-xs font-medium text-gray-700">Dígitos</label>
                    <input id="eti-digitos" v-model.number="rango.digitos" type="number" min="1" max="20" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                  </div>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button type="button" :disabled="sugiriendoRango" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 border-b-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-60" @click="sugerirSiguienteCodigo">
                    {{ sugiriendoRango ? 'Consultando…' : 'Sugerir siguiente (libros)' }}
                  </button>
                  <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 border-b-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-60" @click="generarRango">Generar lista con este rango</button>
                </div>
              </div>

              <div>
                <div class="mb-2 flex items-center justify-between gap-2">
                  <label for="eti-codigos" class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-indigo-600 text-[11px] text-white">2</span>
                    Códigos a imprimir (uno por línea)
                  </label>
                  <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700">{{ cantidadCodigos }} {{ cantidadCodigos === 1 ? 'código' : 'códigos' }}</span>
                </div>
                <textarea
                  id="eti-codigos"
                  v-model="codigosTexto"
                  rows="6"
                  :placeholder="rango.formato === 'corto' ? EJEMPLO_CORTO : EJEMPLO_LARGO"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div class="flex flex-wrap items-center gap-3 border-t border-dashed border-gray-200 pt-4">
                <button type="button" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700" @click="abrirImpresion">
                  Generar e imprimir
                </button>
                <span class="text-xs text-gray-500">Se abre en una pestaña nueva</span>
              </div>
            </div>
          </section>

          <aside class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <p class="rounded-t-2xl border-b border-gray-100 bg-indigo-50/40 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700">Así sale cada etiqueta</p>
            <div class="p-4">
              <div class="tema-claro flex flex-col items-center rounded-xl border border-dashed border-gray-300 bg-white p-3">
                <img :src="muestraUrl" alt="Muestra de la etiqueta con su código de barra" class="max-w-full" />
                <span class="text-[10.5px] font-black text-black">{{ INSTITUCION }}</span>
              </div>
              <p class="mt-2.5 text-center text-xs text-gray-500">Muestra con el primer código de la lista.</p>
            </div>
          </aside>
        </div>
      </template>
    </div>
  </StaffLayout>
</template>
