<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import jsQR from 'jsqr'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import PildoraHorario from '@/components/portal/PildoraHorario.vue'
import apiUsuario from '@/services/apiUsuario'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'
import { formatearInputIdentificador, identificadorValido, mismoDocumento } from '@/utils/documento'
import type { EstadoPortal, HorarioBiblioteca } from '@/types'

const auth = useUsuarioAuthStore()
const toast = useToast()
const router = useRouter()
const route = useRoute()

const modo = ref<'menu' | 'scan' | 'success'>('menu')
const rut = ref('')
const errorRut = ref('')
const errorQr = ref('')
const scanError = ref('')
const enviando = ref(false)

const horario = ref<HorarioBiblioteca | null>(null)
const rutInput = ref<HTMLInputElement | null>(null)

onMounted(async () => {
  // Los botones "Escanear QR" y "Usar mi RUT" del inicio llegan acá con ?metodo=.
  if (route.query.metodo === 'qr') iniciarEscaneo()
  if (route.query.metodo === 'rut') {
    await nextTick()
    // En el teléfono no se pone el cursor solo: abriría el teclado sin que la página
    // alcance a acomodarse. Se lleva la tarjeta a la vista y la persona toca el campo.
    if (window.matchMedia('(pointer: coarse)').matches) {
      const tarjeta = rutInput.value?.closest('section') ?? rutInput.value
      tarjeta?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    } else {
      rutInput.value?.focus()
    }
  }
  try {
    const { data } = await apiUsuario.get<EstadoPortal>('/mi/estado')
    horario.value = data.horario
  } catch {
    horario.value = null
  }
})

function onRutInput(event: Event) {
  rut.value = formatearInputIdentificador(event)
  errorRut.value = ''
}

// Cuánto se ve el mensaje de éxito antes de volver al inicio; la barra de progreso dura lo mismo.
const MS_EXITO = 1250

async function registrar(via: 'manual' | 'qr', codigoQr?: string) {
  enviando.value = true
  try {
    const peticion = apiUsuario.post('/mi/entrada', via === 'manual' ? { rut: rut.value, via } : { codigo: codigoQr, via })
    // Con QR se deja ver la animación de carga aunque el servidor responda al instante.
    await Promise.all([peticion, via === 'qr' ? new Promise((r) => setTimeout(r, 450)) : null])
    modo.value = 'success'
    rut.value = ''
    // Tras confirmar se vuelve al inicio del portal (no a esta pantalla, que parecería
    // pedir la entrada de nuevo), dejando un momento el mensaje de éxito.
    setTimeout(() => {
      router.push({ name: 'portal-home' })
    }, MS_EXITO)
  } catch (e: any) {
    const mensaje = e?.response?.data?.message ?? 'No se pudo registrar la entrada'
    // Ya había marcado su entrada hoy: no es un error, solo se avisa y se vuelve al inicio.
    if (e?.response?.data?.ya_registrada) {
      toast.info(mensaje)
      rut.value = ''
      router.push({ name: 'portal-home' })
      return
    }
    if (via === 'manual') {
      errorRut.value = mensaje
    } else {
      errorQr.value = mensaje
      modo.value = 'menu'
    }
  } finally {
    enviando.value = false
  }
}

function confirmarRut() {
  if (enviando.value) return
  if (!rut.value) {
    errorRut.value = 'Escribe tu RUT (o pasaporte/DNI) para registrar tu ingreso.'
    return
  }
  if (!identificadorValido(rut.value)) {
    errorRut.value = 'RUT inválido. Revisa el número y el dígito verificador.'
    return
  }
  if (!mismoDocumento(rut.value, auth.usuario?.rut)) {
    errorRut.value = 'El RUT o documento ingresado no coincide con tu cuenta.'
    return
  }
  registrar('manual')
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
  errorQr.value = ''
  modo.value = 'scan'
  await nextTick()
  // Los navegadores solo entregan la cámara en sitios seguros (https o localhost).
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    scanError.value = 'La cámara solo se puede usar cuando el portal se abre con https. Mientras tanto, ingresa con tu RUT.'
    return
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    if (!videoEl.value) return
    videoEl.value.srcObject = stream
    await videoEl.value.play()
    escanearFrame()
  } catch {
    scanError.value = 'No se pudo acceder a la cámara. Revisa los permisos del navegador o ingresa con tu RUT.'
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
    <div class="max-w-3xl mx-auto">
      <button
        @click="router.push({ name: 'portal-home' })"
        class="mb-5 flex items-center gap-2 text-sm text-gray-600 hover:text-indigo-700"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver al inicio
      </button>

      <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 class="text-2xl sm:text-3xl font-serif font-bold tracking-tight text-gray-900">Marcar entrada</h1>
          <p class="mt-1 text-sm text-gray-500">Registra tu ingreso a la biblioteca con el método que te acomode.</p>
        </div>
        <PildoraHorario v-if="horario" :horario="horario" class="self-start sm:self-auto" />
      </div>

      <div v-if="modo === 'success'" class="bg-white border border-gray-200 rounded-2xl shadow-sm px-6 py-12 text-center">
        <div class="exito-insignia inline-flex items-center justify-center w-20 h-20 rounded-full bg-emerald-100 text-emerald-600 mb-4">
          <svg class="w-12 h-12" fill="none" viewBox="0 0 52 52" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <circle class="exito-circulo" cx="26" cy="26" r="23" />
            <path class="exito-check" d="M15 27l7 7 15-15" />
          </svg>
        </div>
        <h2 class="text-2xl font-serif font-bold text-gray-900 mb-1">¡Bienvenido/a!</h2>
        <p class="text-lg text-gray-600">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</p>
        <p class="text-sm text-gray-400 mt-3">Entrada registrada correctamente</p>
        <div class="mx-auto mt-6 h-1 w-40 overflow-hidden rounded-full bg-gray-100">
          <div class="exito-progreso h-full rounded-full bg-emerald-500" :style="{ animationDuration: `${MS_EXITO}ms` }"></div>
        </div>
        <p class="mt-2 text-xs text-gray-400">Volviendo al inicio…</p>
      </div>

      <template v-else>
        <div class="grid gap-5 md:grid-cols-2">
          <section class="flex flex-col bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
            <div class="flex items-start justify-between gap-3">
              <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z" />
                </svg>
              </span>
              <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-indigo-700">
                Recomendado en celular
              </span>
            </div>
            <h2 class="mt-5 font-sans text-lg font-bold text-gray-900">Escanear código QR</h2>

            <template v-if="modo === 'scan'">
              <div class="mt-4 relative w-full aspect-square bg-black rounded-xl overflow-hidden">
                <video ref="videoEl" class="w-full h-full object-cover" playsinline muted></video>
                <div class="absolute inset-8 border-2 border-white/70 rounded-lg pointer-events-none overflow-hidden">
                  <div v-if="!enviando && !scanError" class="linea-escaneo"></div>
                </div>
                <Transition
                  enter-active-class="transition duration-150 ease-out"
                  enter-from-class="opacity-0"
                  leave-active-class="transition duration-100 ease-in"
                  leave-to-class="opacity-0"
                >
                  <div
                    v-if="enviando"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-indigo-950/70 backdrop-blur-sm text-white"
                    role="status"
                  >
                    <div class="relative h-16 w-16">
                      <span class="absolute inset-0 rounded-full border-4 border-white/20"></span>
                      <span class="absolute inset-0 rounded-full border-4 border-transparent border-t-white motion-safe:animate-spin"></span>
                      <svg class="absolute inset-0 m-auto w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z" />
                      </svg>
                    </div>
                    <p class="text-sm font-semibold">Código detectado</p>
                    <p class="-mt-3 text-xs text-indigo-100/90">Registrando tu ingreso…</p>
                  </div>
                </Transition>
              </div>
              <canvas ref="canvasEl" class="hidden"></canvas>
              <p v-if="scanError" class="mt-4 text-sm text-red-700 bg-red-50 border border-red-100 rounded-lg px-3 py-2.5">
                {{ scanError }}
              </p>
              <p v-else class="mt-3 text-sm text-gray-500 text-center">
                {{ enviando ? 'Registrando tu ingreso…' : 'Apunta la cámara al código QR de la biblioteca' }}
              </p>
              <button
                @click="cancelarEscaneo"
                :disabled="enviando"
                class="mt-4 w-full py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-60"
              >
                Cancelar
              </button>
            </template>

            <template v-else>
              <p class="mt-1.5 text-sm text-gray-600">
                Apunta la cámara al código QR de la biblioteca. Tu ingreso queda registrado al instante.
              </p>
              <ul class="mt-4 space-y-2 text-sm text-gray-600">
                <li class="flex items-center gap-2.5">
                  <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500"></span>
                  Confirma que estás en la biblioteca
                </li>
                <li class="flex items-center gap-2.5">
                  <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500"></span>
                  Sin escribir nada
                </li>
              </ul>
              <p v-if="errorQr" class="mt-4 text-sm text-red-700 bg-red-50 border border-red-100 rounded-lg px-3 py-2.5">
                {{ errorQr }}
              </p>
              <div class="mt-auto pt-6">
                <button
                  @click="iniciarEscaneo"
                  :disabled="enviando"
                  class="w-full flex items-center justify-center gap-2 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-60"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                  </svg>
                  Abrir cámara
                </button>
                <p class="mt-2.5 text-center text-xs text-gray-500">Te pediremos permiso para usar la cámara.</p>
              </div>
            </template>
          </section>

          <section class="flex flex-col bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
            <div class="flex items-start justify-between gap-3">
              <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z" />
                </svg>
              </span>
              <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-800">
                Ideal en computador
              </span>
            </div>
            <h2 class="mt-5 font-sans text-lg font-bold text-gray-900">Ingresar con RUT</h2>
            <p class="mt-1.5 text-sm text-gray-600">Escribe tu RUT sin puntos ni guion; lo formateamos por ti. Si eres extranjero/a, tu pasaporte o DNI.</p>

            <form class="mt-auto pt-5" novalidate @submit.prevent="confirmarRut">
              <label for="rut-entrada" class="block text-xs font-semibold text-gray-700 mb-1.5">RUT</label>
              <input
                id="rut-entrada"
                ref="rutInput"
                :value="rut"
                @input="onRutInput"
                type="text"
                autocomplete="off"
                maxlength="20"
                placeholder="12.345.678-9"
                :aria-invalid="errorRut ? 'true' : 'false'"
                aria-describedby="rut-entrada-ayuda"
                class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:border-transparent"
                :class="errorRut ? 'border-red-300 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
              />
              <p id="rut-entrada-ayuda" class="mt-1.5 text-xs" :class="errorRut ? 'text-red-600' : 'text-gray-500'">
                {{ errorRut || 'Ejemplo: 12345678k' }}
              </p>
              <button
                type="submit"
                :disabled="enviando"
                class="mt-5 w-full flex items-center justify-center gap-2 py-2.5 border border-indigo-600 text-indigo-700 text-sm font-semibold rounded-lg hover:bg-indigo-50 transition-colors disabled:opacity-60"
              >
                {{ enviando && modo === 'menu' ? 'Registrando…' : 'Registrar entrada' }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
              </button>
            </form>
          </section>
        </div>

        <div class="mt-5 flex items-start gap-2.5 rounded-xl bg-gray-100 px-4 py-3 text-sm text-gray-600">
          <svg class="w-4 h-4 mt-0.5 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          ¿Problemas para registrar tu ingreso? Acércate al mesón de atención.
        </div>
      </template>
    </div>
  </PortalLayout>
</template>

<style scoped>
/* Línea que recorre el visor mientras se busca un código. */
.linea-escaneo {
  position: absolute;
  left: 0;
  right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, #a5b4fc, transparent);
  box-shadow: 0 0 12px 2px rgba(165, 180, 252, 0.7);
  animation: escaneo 2s ease-in-out infinite alternate;
}

@keyframes escaneo {
  from { top: 0; }
  to { top: calc(100% - 2px); }
}

/* Éxito: el círculo se dibuja, luego el check, y la barra cuenta el tiempo hasta volver al inicio. */
.exito-insignia {
  animation: aparecer 0.18s ease-out both;
}

.exito-circulo {
  stroke-dasharray: 145;
  stroke-dashoffset: 145;
  animation: trazar 0.25s ease-out 0.05s forwards;
}

.exito-check {
  stroke-dasharray: 36;
  stroke-dashoffset: 36;
  animation: trazar 0.18s ease-out 0.28s forwards;
}

/* La duración la fija MS_EXITO en el script, para que la barra termine justo al volver al inicio. */
.exito-progreso {
  width: 0;
  animation-name: progreso;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

@keyframes aparecer {
  from { transform: scale(0.6); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

@keyframes trazar {
  to { stroke-dashoffset: 0; }
}

@keyframes progreso {
  to { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .linea-escaneo { display: none; }
  .exito-insignia,
  .exito-circulo,
  .exito-check,
  .exito-progreso { animation: none; stroke-dashoffset: 0; width: 100%; }
}
</style>
