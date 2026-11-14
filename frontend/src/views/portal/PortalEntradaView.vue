<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import jsQR from 'jsqr'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import PildoraHorario from '@/components/portal/PildoraHorario.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import apiUsuario from '@/services/apiUsuario'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'
import { largoSegunModo, mismoDocumento, modoDe, placeholderSegunModo } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import type { EstadoPortal, HorarioBiblioteca } from '@/types'

const auth = useUsuarioAuthStore()
const toast = useToast()
const router = useRouter()
const route = useRoute()

const modo = ref<'menu' | 'scan' | 'success'>('menu')
const rut = ref('')
// Parte con el documento de la propia cuenta: RUT, o pasaporte/DNI si es extranjero.
const { modo: modoRut, escribir: escribirRut, error: errorDelRut } = useModoDocumento(rut, { inicial: modoDe(auth.usuario?.tipo_documento), tuteo: true })
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
  escribirRut(event)
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
  const error = errorDelRut()
  if (error) {
    errorRut.value = error
    return
  }
  if (!mismoDocumento(rut.value, auth.usuario?.rut)) {
    errorRut.value = `El ${modoRut.value === 'rut' ? 'RUT' : 'pasaporte o DNI'} ingresado no coincide con tu cuenta.`
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
    <div class="mx-auto max-w-2xl md:max-w-6xl">
      <!-- Cabecera: el degradado morado del portal, con una puerta que recibe la flecha de entrada. -->
      <section class="cabecera-entrada relative mb-5 overflow-hidden rounded-2xl px-5 py-5 text-white shadow-lg shadow-indigo-900/25 ring-1 ring-inset ring-white/10 sm:px-7 sm:py-6">
        <span class="pointer-events-none absolute -left-16 -top-20 h-56 w-56 rounded-full bg-fuchsia-400/20 blur-3xl" aria-hidden="true"></span>
        <span class="pointer-events-none absolute -bottom-24 right-40 h-56 w-72 rounded-full bg-sky-300/20 blur-3xl" aria-hidden="true"></span>
        <span class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent" aria-hidden="true"></span>

        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div class="min-w-0">
            <button type="button" class="mb-2 flex items-center gap-1 text-xs font-medium text-indigo-100 hover:text-white" @click="router.push({ name: 'portal-home' })">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
              Volver al inicio
            </button>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white sm:text-3xl">Marcar entrada</h1>
            <p class="mt-1 max-w-xl text-sm text-indigo-100">Registra tu ingreso a la biblioteca con el método que te acomode.</p>
          </div>
          <PildoraHorario v-if="horario" :horario="horario" oscura class="relative z-10 self-start backdrop-blur-sm sm:self-auto" />
        </div>

        <!-- Puerta entreabierta con la luz de adentro y la flecha que entra. -->
        <svg class="puerta pointer-events-none absolute bottom-0 right-[17rem] hidden h-[6.5rem] w-40 lg:block" viewBox="0 0 160 104" fill="none" aria-hidden="true">
          <rect x="78" y="8" width="54" height="92" rx="3" fill="#ffffff" fill-opacity="0.06" stroke="#ffffff" stroke-opacity="0.35" />
          <path d="M84 14h42v86H84z" fill="#fde68a" fill-opacity="0.18" />
          <path d="M84 14l26 8v86l-26-8z" fill="#6366f1" fill-opacity="0.55" stroke="#ffffff" stroke-opacity="0.45" stroke-linejoin="round" />
          <circle cx="104" cy="60" r="2.2" fill="#fcd34d" />
          <path d="M70 100h72" stroke="#ffffff" stroke-opacity="0.3" />
          <g class="flecha-entra" stroke="#6ee7b7" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 56h42m-8-8 8 8-8 8" />
            <path d="M8 56h4" stroke-opacity="0.5" />
          </g>
          <g fill="#fde68a">
            <path d="M144 20l1.4 3 3 1.4-3 1.4-1.4 3-1.4-3-3-1.4 3-1.4z" fill-opacity="0.8" />
            <circle cx="60" cy="24" r="1.4" fill-opacity="0.55" />
          </g>
        </svg>
      </section>

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
          <section class="relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-gradient-to-b from-indigo-50/50 to-white p-6 shadow-md shadow-indigo-900/5">
            <span class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-indigo-800 via-indigo-600 to-indigo-400" aria-hidden="true"></span>
            <!-- Visor de QR junto a un teléfono con el código en pantalla. -->
            <svg v-if="modo !== 'scan'" class="pointer-events-none absolute right-5 top-5 h-[4.5rem] w-[6.5rem]" viewBox="0 0 104 72" fill="none" aria-hidden="true">
              <path d="M4 14V4h10M34 4h10v10M44 50v10H34M14 60H4V50" stroke="#a5b4fc" stroke-width="1.6" stroke-linecap="round" />
              <g fill="#c7d2fe"><rect x="14" y="20" width="6" height="6" rx="1" /><rect x="26" y="20" width="6" height="6" rx="1" /><rect x="14" y="34" width="6" height="6" rx="1" /><rect x="27" y="35" width="3.5" height="3.5" rx="0.8" /></g>
              <rect x="62" y="1" width="38" height="70" rx="7" fill="#ffffff" stroke="#a5b4fc" stroke-width="1.5" />
              <rect x="67" y="9" width="28" height="48" rx="3" fill="#eef2ff" />
              <g fill="#4f46e5"><rect x="72" y="16" width="6" height="6" rx="1" /><rect x="83" y="16" width="6" height="6" rx="1" /><rect x="72" y="27" width="6" height="6" rx="1" /><rect x="83" y="28" width="3" height="3" rx="0.6" /><rect x="87" y="32" width="3" height="3" rx="0.6" /></g>
              <path class="linea-mini" d="M71 44h20" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <span class="self-start rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-indigo-700 ring-1 ring-inset ring-indigo-100">
              Recomendado en celular
            </span>
            <h2 class="mt-4 pr-28 font-serif text-xl font-bold text-gray-900">Escanear código QR</h2>

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
              <p class="mt-1.5 max-w-xs text-sm text-gray-600">
                Apunta la cámara al código QR de la biblioteca. Tu ingreso queda registrado al instante.
              </p>
              <ul class="mt-4 flex flex-wrap gap-1.5 text-xs text-gray-700">
                <li v-for="ventaja in ['Confirma que estás en la biblioteca', 'Sin escribir nada']" :key="ventaja" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1 shadow-sm">
                  <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                  {{ ventaja }}
                </li>
              </ul>
              <p v-if="errorQr" class="mt-4 text-sm text-red-700 bg-red-50 border border-red-100 rounded-lg px-3 py-2.5">
                {{ errorQr }}
              </p>
              <div class="mt-auto pt-6">
                <button
                  @click="iniciarEscaneo"
                  :disabled="enviando"
                  class="w-full flex items-center justify-center gap-2 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] hover:bg-indigo-700 transition-colors disabled:opacity-60"
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

          <section class="relative flex flex-col overflow-hidden rounded-2xl border border-amber-200/70 bg-gradient-to-b from-amber-50/80 to-amber-50/20 p-6 shadow-md shadow-amber-900/5">
            <span class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-amber-700 via-amber-500 to-amber-300" aria-hidden="true"></span>
            <!-- Credencial con foto y líneas de datos, un poco inclinada. -->
            <svg class="pointer-events-none absolute right-5 top-6 h-16 w-[6.5rem]" viewBox="0 0 104 64" fill="none" aria-hidden="true">
              <g transform="rotate(-7 52 32)">
                <rect x="6" y="8" width="92" height="50" rx="6" fill="#ffffff" stroke="#e7c98f" stroke-width="1.5" />
                <circle cx="27" cy="27" r="7" fill="#fef3c7" stroke="#d4a65a" stroke-width="1.4" />
                <path d="M15 48c1.5-7 6.5-10 12-10s10.5 3 12 10" stroke="#d4a65a" stroke-width="1.4" stroke-linecap="round" />
                <path d="M48 24h36M48 32h30M48 40h22" stroke="#e7c98f" stroke-width="2.2" stroke-linecap="round" />
              </g>
            </svg>
            <span class="self-start rounded-full bg-amber-100/80 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-800 ring-1 ring-inset ring-amber-200">
              Ideal en computador
            </span>
            <h2 class="mt-4 pr-28 font-serif text-xl font-bold text-gray-900">Ingresar con RUT</h2>
            <p class="mt-1.5 max-w-xs text-sm text-gray-600">Escribe tu RUT sin puntos ni guion; lo formateamos por ti. Si eres extranjero/a, tu pasaporte o DNI.</p>

            <form class="mt-auto pt-5" novalidate @submit.prevent="confirmarRut">
              <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                <label for="rut-entrada" class="block text-xs font-semibold text-gray-700">{{ modoRut === 'rut' ? 'RUT' : 'Pasaporte o DNI' }}</label>
                <SelectorDocumento v-model="modoRut" compacto etiqueta="¿Con qué documento ingresas?" />
              </div>
              <input
                id="rut-entrada"
                ref="rutInput"
                :value="rut"
                @input="onRutInput"
                type="text"
                autocomplete="off"
                :maxlength="largoSegunModo(modoRut)"
                :placeholder="placeholderSegunModo(modoRut)"
                :aria-invalid="errorRut ? 'true' : 'false'"
                aria-describedby="rut-entrada-ayuda"
                class="w-full rounded-lg border bg-white px-3.5 py-2.5 font-mono text-sm tracking-wide text-gray-900 shadow-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:border-transparent"
                :class="errorRut ? 'border-red-300 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
              />
              <p id="rut-entrada-ayuda" class="mt-1.5 text-xs" :class="errorRut ? 'text-red-600' : 'text-gray-500'">
                <template v-if="errorRut">{{ errorRut }}</template>
                <template v-else-if="modoRut === 'rut'">Ejemplo: <span class="font-semibold text-gray-700">12345678k</span></template>
                <template v-else>Tal como aparece en tu pasaporte o DNI</template>
              </p>
              <button
                type="submit"
                :disabled="enviando"
                class="mt-4 w-full flex items-center justify-center gap-2 py-2.5 border-2 border-b-[3px] border-indigo-600 bg-white text-indigo-700 text-sm font-bold rounded-lg hover:bg-indigo-50 transition-colors disabled:opacity-60"
              >
                {{ enviando && modo === 'menu' ? 'Registrando…' : 'Registrar entrada' }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
              </button>
            </form>
          </section>
        </div>

        <div class="mt-5 flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 shadow-sm">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          </span>
          <span>¿Problemas para registrar tu ingreso? <strong class="font-semibold text-gray-900">Acércate al mesón de atención.</strong></span>
        </div>
      </template>
    </div>
  </PortalLayout>
</template>

<style scoped>
/* Cabecera: el degradado morado institucional con trama de puntos que se apaga hacia el texto. */
.cabecera-entrada {
  background:
    radial-gradient(circle, rgb(255 255 255 / 0.13) 1px, transparent 1.3px) 0 0 / 16px 16px,
    linear-gradient(135deg, #2d1b69 0%, #3b28a3 30%, #4338ca 60%, #4f46e5 100%);
}
.cabecera-entrada::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, #2d1b69 0%, rgb(45 27 105 / 0.85) 30%, transparent 70%);
  opacity: 0.55;
  pointer-events: none;
}
/* La flecha entra y vuelve; en la tarjeta QR, la línea verde recorre la pantalla del teléfono. */
@media (prefers-reduced-motion: no-preference) {
  .puerta .flecha-entra { animation: entrar 2.4s ease-in-out infinite; }
  .linea-mini { animation: recorrer 2.2s ease-in-out infinite alternate; }
}
@keyframes entrar {
  0%, 100% { transform: translateX(0); opacity: 1; }
  50% { transform: translateX(6px); opacity: 0.7; }
}
@keyframes recorrer {
  from { transform: translateY(-26px); }
  to { transform: translateY(8px); }
}

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
