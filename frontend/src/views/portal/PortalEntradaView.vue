<script setup lang="ts">
import { nextTick, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import jsQR from 'jsqr'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import apiUsuario from '@/services/apiUsuario'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { formatRut, validarRut } from '@/composables/useRut'

const auth = useUsuarioAuthStore()
const router = useRouter()

type Modo = 'menu' | 'rut' | 'scan' | 'success' | 'error'
const modo = ref<Modo>('menu')
const rut = ref('')
const errorMsg = ref('')
const enviando = ref(false)
const scanError = ref('')
const lastVia = ref<'manual' | 'qr'>('manual')

const teclas = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'K', '0', '⌫']

function onRutInputField(event: Event) {
  rut.value = formatRut((event.target as HTMLInputElement).value)
}

function handleTecla(t: string) {
  if (t === '⌫') {
    const cleaned = rut.value.replace(/[^0-9kK]/g, '')
    rut.value = formatRut(cleaned.slice(0, -1))
  } else {
    const cleaned = rut.value.replace(/[^0-9kK]/g, '') + t
    if (cleaned.length <= 9) rut.value = formatRut(cleaned)
  }
}

async function registrar(via: 'manual' | 'qr', codigoQr?: string) {
  lastVia.value = via
  enviando.value = true
  try {
    await apiUsuario.post('/mi/entrada', via === 'manual' ? { rut: rut.value, via } : { codigo: codigoQr, via })
    modo.value = 'success'
    rut.value = ''
    // Antes esto volvía al mismo "menú" de esta pantalla (Escanear QR / Ingresar RUT),
    // que se veía igual a como si hubiera que registrar la entrada de nuevo — confuso
    // justo después de confirmar. Ahora se va al inicio del portal, dejando el mensaje
    // de éxito visible unos segundos antes de navegar.
    setTimeout(() => {
      router.push({ name: 'portal-home' })
    }, 2500)
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message ?? 'No se pudo registrar la entrada'
    modo.value = 'error'
    setTimeout(() => setModo(lastVia.value === 'manual' ? 'rut' : 'menu'), 3000)
  } finally {
    enviando.value = false
  }
}

function confirmarRut() {
  if (!validarRut(rut.value)) {
    errorMsg.value = 'RUT inválido. Verifica el formato.'
    modo.value = 'error'
    lastVia.value = 'manual'
    setTimeout(() => setModo('rut'), 3000)
    return
  }
  if (rut.value !== auth.usuario?.rut) {
    errorMsg.value = 'El RUT ingresado no coincide con tu cuenta.'
    modo.value = 'error'
    lastVia.value = 'manual'
    setTimeout(() => setModo('rut'), 3000)
    return
  }
  registrar('manual')
}

function setModo(m: Modo) {
  modo.value = m
}

// --- Escaneo de QR por cámara ---
const videoEl = ref<HTMLVideoElement | null>(null)
const canvasEl = ref<HTMLCanvasElement | null>(null)
let stream: MediaStream | null = null
let rafId: number | undefined

function detenerCamara() {
  if (rafId !== undefined) cancelAnimationFrame(rafId)
  rafId = undefined
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

function escanearFrame() {
  if (modo.value !== 'scan' || !videoEl.value || !canvasEl.value) return

  const video = videoEl.value
  if (video.readyState === video.HAVE_ENOUGH_DATA) {
    const canvas = canvasEl.value
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    const ctx = canvas.getContext('2d')
    if (ctx) {
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
      const resultado = jsQR(imageData.data, imageData.width, imageData.height)
      if (resultado?.data) {
        detenerCamara()
        registrar('qr', resultado.data)
        return
      }
    }
  }
  rafId = requestAnimationFrame(escanearFrame)
}

async function iniciarEscaneo() {
  scanError.value = ''
  modo.value = 'scan'
  await nextTick()
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    if (!videoEl.value) return
    videoEl.value.srcObject = stream
    await videoEl.value.play()
    escanearFrame()
  } catch {
    scanError.value = 'No se pudo acceder a la cámara. Revisa los permisos del navegador o usa el ingreso por RUT.'
  }
}

function cancelarEscaneo() {
  detenerCamara()
  modo.value = 'menu'
}

onUnmounted(detenerCamara)
</script>

<template>
  <PortalLayout>
    <div class="max-w-sm mx-auto flex flex-col items-center">
      <button
        @click="router.push({ name: 'portal-home' })"
        class="self-start mb-6 flex items-center gap-2 text-sm text-gray-600 hover:text-indigo-700"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver al inicio
      </button>

      <div v-if="modo === 'menu'" class="w-full space-y-3">
        <h1 class="text-xl font-serif font-bold text-gray-900 text-center mb-2">Marcar entrada</h1>
        <button
          @click="iniciarEscaneo"
          :disabled="enviando"
          class="w-full flex items-center justify-center gap-3 py-5 bg-indigo-600 text-white text-lg font-semibold rounded-lg shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-60"
        >
          <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m3 0h-3m0 0v3m0-3v-3" />
          </svg>
          Escanear QR
        </button>
        <button
          @click="modo = 'rut'"
          class="w-full flex items-center justify-center gap-3 py-5 bg-white text-gray-900 text-lg font-semibold rounded-lg shadow-sm border border-gray-200 hover:border-indigo-400 hover:text-indigo-700 transition-colors"
        >
          <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 2a1 1 0 00-1 1v1a1 1 0 001 1h6a1 1 0 001-1V3a1 1 0 00-1-1H9zM4 7a2 2 0 012-2h.5a.5.5 0 01.5.5V6a2 2 0 002 2h6a2 2 0 002-2v-.5a.5.5 0 01.5-.5H18a2 2 0 012 2v13a2 2 0 01-2 2H6a2 2 0 01-2-2V7z" />
          </svg>
          Ingresar RUT
        </button>
      </div>

      <div v-else-if="modo === 'scan'" class="w-full flex flex-col items-center">
        <h1 class="text-xl font-serif font-bold text-gray-900 text-center mb-4">Escanea el código QR de la biblioteca</h1>
        <div class="relative w-full aspect-square bg-black rounded-lg overflow-hidden border border-gray-200">
          <video ref="videoEl" class="w-full h-full object-cover" playsinline muted></video>
          <div class="absolute inset-8 border-2 border-white/70 rounded-lg pointer-events-none"></div>
        </div>
        <canvas ref="canvasEl" class="hidden"></canvas>
        <p v-if="scanError" class="mt-4 text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2.5 text-center">
          {{ scanError }}
        </p>
        <p v-else class="mt-4 text-sm text-gray-500 text-center">Apunta la cámara al código QR mostrado en la biblioteca</p>
        <button
          @click="cancelarEscaneo"
          class="mt-4 w-full py-3.5 bg-white border border-gray-300 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-50 transition-colors"
        >
          Cancelar
        </button>
      </div>

      <div v-else-if="modo === 'rut'" class="w-full">
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-5 mb-4">
          <label for="rut-confirm" class="block text-sm font-medium text-gray-500 mb-2">Confirma tu RUT</label>
          <input
            id="rut-confirm"
            :value="rut"
            @input="onRutInputField"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="12"
            placeholder="XX.XXX.XXX-X"
            class="w-full text-3xl font-mono font-bold text-center py-4 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 min-h-[60px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
          />
          <p class="mt-2 text-xs text-gray-400 text-center">Puedes escribirlo con el teclado de tu teléfono o usar el teclado numérico</p>
        </div>

        <div class="grid grid-cols-3 gap-2 mb-4">
          <button
            v-for="t in teclas"
            :key="t"
            @click="handleTecla(t)"
            class="py-4 text-xl font-semibold rounded-lg border transition-colors active:scale-95"
            :class="t === '⌫' ? 'bg-red-50 text-red-600 border-red-200 hover:bg-red-100' : 'bg-white text-gray-900 border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'"
          >
            {{ t }}
          </button>
        </div>

        <div class="flex gap-3">
          <button
            @click="modo = 'menu'; rut = ''"
            class="flex-1 py-3.5 bg-white border border-gray-300 text-gray-700 text-base font-semibold rounded-lg hover:bg-gray-50 transition-colors"
          >
            Volver
          </button>
          <button
            @click="confirmarRut"
            :disabled="!rut || enviando"
            class="flex-1 py-3.5 bg-indigo-600 text-white text-base font-semibold rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50"
          >
            Confirmar
          </button>
        </div>
      </div>

      <div v-else-if="modo === 'success'" class="text-center py-10">
        <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-emerald-100 text-emerald-600 mb-4">
          <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h2 class="text-2xl font-serif font-bold text-gray-900 mb-2">¡Bienvenido/a!</h2>
        <p class="text-xl text-gray-600">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</p>
        <p class="text-sm text-gray-400 mt-3">Entrada registrada correctamente</p>
      </div>

      <div v-else-if="modo === 'error'" class="text-center py-10">
        <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-red-100 text-red-600 mb-4">
          <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
        </div>
        <h2 class="text-2xl font-serif font-bold text-red-700 mb-2">Error</h2>
        <p class="text-lg text-gray-600">{{ errorMsg }}</p>
      </div>
    </div>
  </PortalLayout>
</template>
