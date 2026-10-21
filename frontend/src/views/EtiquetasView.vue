<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import JsBarcode from 'jsbarcode'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import EncabezadoPagina from '@/components/ui/EncabezadoPagina.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import EtiquetasLomo from '@/components/libros/EtiquetasLomo.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const route = useRoute()
const router = useRouter()

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
const rango = reactive({ prefijo: '', desde: 1, cantidad: 10, digitos: 14 })
const sugiriendoRango = ref(false)

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
    <div class="max-w-4xl mx-auto">
      <EncabezadoPagina titulo="Etiquetas de Código" descripcion="Códigos de barra Code128 y etiquetas de lomo listos para imprimir" />

      <div class="flex flex-col sm:flex-row gap-1 mb-6 bg-white rounded-xl shadow-sm p-1" role="tablist">
        <button
          v-for="p in ([{ clave: 'codigos', label: 'Códigos de barra' }, { clave: 'lomo', label: 'Etiquetas de lomo' }] as const)"
          :key="p.clave"
          type="button"
          role="tab"
          :aria-selected="pestana === p.clave"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === p.clave ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
          @click="elegirPestana(p.clave)"
        >
          {{ p.label }}
        </button>
      </div>

      <template v-if="pestana === 'lomo'">
        <GuiaUso
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
          :pasos="[
            'Pulse <strong>Sugerir siguiente (libros)</strong> para partir desde el primer código de libro que todavía no se usa.',
            'Indique la <strong>cantidad</strong> y pulse <strong>Generar lista con este rango</strong>, o pegue códigos a mano (uno por línea).',
            'Pulse <strong>Generar e imprimir</strong>: se abre una pestaña con las etiquetas y su botón Imprimir.',
          ]"
          consejo="Imprimir etiquetas no registra nada: los códigos se asignan al catalogar cada copia."
        />

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
              <input v-model.number="rango.digitos" type="number" min="1" max="20" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
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
            rows="8"
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
      </template>
    </div>
  </StaffLayout>
</template>
