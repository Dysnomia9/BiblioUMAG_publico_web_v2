<script setup lang="ts">
import { computed, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import { CODIGOS_EN_EL_MAPA, PISOS_MAPA, type LugarMapa } from '@/utils/mapaSalas'
import { sinTildes } from '@/utils/texto'
import type { Sala, TramoReserva } from '@/types'

/**
 * Las salas de estudio como en el plano de la biblioteca (utils/mapaSalas.ts), con lo que
 * pasa hoy en cada una: libre, ocupada, tu reserva o que no se reserva aquí. Al tocar una sala
 * se ven sus horarios de hoy; reservar y cancelar siguen el mismo camino que la línea de tiempo
 * (los hace PortalSalasView).
 */
const props = defineProps<{
  salas: Sala[]
  ahoraMin: number
  /** Primera hora en que se puede empezar una reserva hoy. */
  primerInicioMin: number
  cierraMin: number
  granularidad: number
  duracionMinima: number
  diaCerrado: boolean
  busqueda: string
  esMia: (tramo: TramoReserva) => boolean
  meIncluyeron: (tramo: TramoReserva) => boolean
}>()

const emit = defineEmits<{ reservar: [sala: Sala, inicioMin: number]; cancelar: [sala: Sala, tramo: TramoReserva] }>()

const aMinutos = (hhmm: string) => {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}
const aHora = (min: number) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`

const porCodigo = computed(() => new Map(props.salas.map((s) => [s.codigo, s])))
/** Las salas del sistema que no están en el plano (van aparte, bajo el mapa). */
const otrasSalas = computed(() => props.salas.filter((s) => !CODIGOS_EN_EL_MAPA.has(s.codigo)))

type Franja = { inicio: number; fin: number; tipo: 'libre' | 'ocupada' | 'mia'; tramo?: TramoReserva }

/** Lo que queda del día en la sala: los espacios libres (que alcanzan para una reserva) y las reservas. */
function franjas(sala: Sala): Franja[] {
  const paso = props.granularidad
  let cursor = Math.max(props.primerInicioMin, Math.ceil(props.ahoraMin / paso) * paso)
  const lista: Franja[] = []
  const reservas = sala.tramos
    .map((tramo) => ({ tramo, inicio: aMinutos(tramo.hora_inicio), fin: aMinutos(tramo.hora_fin) }))
    .filter((r) => r.fin > props.ahoraMin)
    .sort((a, b) => a.inicio - b.inicio)

  for (const r of reservas) {
    if (r.inicio > cursor && cursor < props.cierraMin) lista.push({ inicio: cursor, fin: Math.min(r.inicio, props.cierraMin), tipo: 'libre' })
    lista.push({ inicio: r.inicio, fin: r.fin, tipo: props.esMia(r.tramo) ? 'mia' : 'ocupada', tramo: r.tramo })
    cursor = Math.max(cursor, Math.ceil(r.fin / paso) * paso)
  }
  if (cursor < props.cierraMin) lista.push({ inicio: cursor, fin: props.cierraMin, tipo: 'libre' })

  return lista.filter((f) => f.tipo !== 'libre' || f.fin - f.inicio >= props.duracionMinima)
}

type TipoEstado = 'libre' | 'mas_tarde' | 'ocupada' | 'mia' | 'completa' | 'cerrada' | 'administracion' | 'fuera'

const ESTILOS: Record<TipoEstado, { caja: string; punto: string; texto: string }> = {
  libre: { caja: 'border-emerald-300 bg-emerald-50 hover:border-emerald-500 hover:bg-emerald-100', punto: 'bg-emerald-500', texto: 'text-emerald-800' },
  mas_tarde: { caja: 'border-amber-300 bg-amber-50 hover:border-amber-500 hover:bg-amber-100', punto: 'bg-amber-500', texto: 'text-amber-800' },
  ocupada: { caja: 'border-red-300 bg-red-50 hover:border-red-500 hover:bg-red-100', punto: 'bg-red-500', texto: 'text-red-700' },
  mia: { caja: 'border-indigo-400 bg-indigo-50 hover:border-indigo-600 hover:bg-indigo-100', punto: 'bg-indigo-600', texto: 'text-indigo-800' },
  completa: { caja: 'border-gray-300 bg-gray-50 hover:border-gray-400', punto: 'bg-gray-400', texto: 'text-gray-500' },
  cerrada: { caja: 'border-gray-200 bg-gray-50', punto: 'bg-gray-300', texto: 'text-gray-500' },
  administracion: { caja: 'border-gray-300 bg-gray-200/70', punto: 'bg-gray-400', texto: 'text-gray-500' },
  fuera: { caja: 'border-dashed border-gray-300 bg-white', punto: 'bg-gray-300', texto: 'text-gray-400' },
}

/** Cómo está hoy la sala de ese lugar del plano, en pocas palabras. */
function estado(lugar: LugarMapa | null, sala: Sala | undefined): { tipo: TipoEstado; texto: string } {
  if (lugar?.soloAdministracion) return { tipo: 'administracion', texto: 'Solo administración' }
  if (!sala) return { tipo: 'fuera', texto: 'No se reserva en línea' }
  if (props.diaCerrado) return { tipo: 'cerrada', texto: 'Cerrada hoy' }

  const dia = franjas(sala)
  const mia = dia.find((f) => f.tipo === 'mia')
  if (mia) return { tipo: 'mia', texto: `Tu reserva ${aHora(mia.inicio)}` }
  const libre = dia.find((f) => f.tipo === 'libre')
  const ocupadaAhora = dia.some((f) => f.tipo === 'ocupada' && f.inicio <= props.ahoraMin)
  if (!libre) return { tipo: 'completa', texto: 'Sin horas libres hoy' }
  if (ocupadaAhora) return { tipo: 'ocupada', texto: `Ocupada · libre ${aHora(libre.inicio)}` }
  if (libre.inicio <= props.ahoraMin + props.granularidad) return { tipo: 'libre', texto: libre.fin < props.cierraMin ? `Libre hasta ${aHora(libre.fin)}` : 'Libre' }
  return { tipo: 'mas_tarde', texto: `Libre desde ${aHora(libre.inicio)}` }
}

/** Las logias van con su número grande, como en la tarjeta; las salas con nombre, más chico. */
const esNumero = (etiqueta: string) => /^\d+$/.test(etiqueta)

/** Se toca para ver sus horarios: las que no se reservan aquí solo se ven. */
const seAbre = (tipo: TipoEstado) => !['administracion', 'fuera', 'cerrada'].includes(tipo)

function coincide(etiqueta: string, sala: Sala | undefined) {
  const q = sinTildes(props.busqueda.trim())
  return !q || [etiqueta, sala?.nombre ?? '', sala?.codigo ?? ''].some((t) => sinTildes(t).includes(q))
}

const lugares = (lugar: LugarMapa) => ({ lugar, sala: porCodigo.value.get(lugar.codigo), ...estado(lugar, porCodigo.value.get(lugar.codigo)) })
const pisos = computed(() =>
  PISOS_MAPA.map((piso) => ({
    nombre: piso.nombre,
    lados: [piso.izquierda, piso.derecha].map((lado) => ({ borde: lado.borde, tramos: lado.tramos.map((tramo) => tramo.map(lugares)) })),
  })),
)
const otras = computed(() => otrasSalas.value.map((sala) => ({ lugar: null, sala, ...estado(null, sala) })))

const TEXTO_NO_SE_ABRE: Partial<Record<TipoEstado, string>> = {
  administracion: 'Esta sala la reserva la administración de la biblioteca: pídela en el mesón.',
  fuera: 'Esta sala no se reserva en línea: pregunta en el mesón.',
  cerrada: 'Hoy la biblioteca no atiende.',
}

// ---------- Horarios de hoy de la sala elegida ----------
const elegidaId = ref<number | null>(null)
const elegida = computed(() => props.salas.find((s) => s.id === elegidaId.value) ?? null)
const diaElegida = computed(() => (elegida.value ? franjas(elegida.value) : []))
const primeraLibre = computed(() => diaElegida.value.find((f) => f.tipo === 'libre') ?? null)

function reservar(inicio: number) {
  const sala = elegida.value
  elegidaId.value = null
  if (sala) emit('reservar', sala, inicio)
}

function cancelar(tramo: TramoReserva) {
  const sala = elegida.value
  elegidaId.value = null
  if (sala) emit('cancelar', sala, tramo)
}

const LEYENDA: { tipo: TipoEstado; texto: string }[] = [
  { tipo: 'libre', texto: 'Libre ahora' },
  { tipo: 'mas_tarde', texto: 'Libre más tarde' },
  { tipo: 'ocupada', texto: 'Ocupada ahora' },
  { tipo: 'mia', texto: 'Tu reserva' },
  { tipo: 'administracion', texto: 'Solo administración' },
  { tipo: 'fuera', texto: 'No se reserva en línea' },
]
</script>

<template>
  <div>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <section v-for="piso in pisos" :key="piso.nombre" class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm sm:p-5" :aria-label="piso.nombre">
        <h3 class="mb-3 flex items-center gap-2 font-sans text-sm font-bold text-gray-900">
          <span class="flex h-7 min-w-[2rem] items-center justify-center rounded-lg bg-indigo-600 px-2 text-xs text-white">{{ piso.nombre.slice(0, 3) }}</span>
          {{ piso.nombre.charAt(0).toUpperCase() + piso.nombre.slice(1) }}
        </h3>
        <div class="flex items-stretch gap-1.5 sm:gap-3">
          <template v-for="(lado, i) in piso.lados" :key="i">
            <!-- El pasillo, entre los dos lados -->
            <div v-if="i === 1" class="flex w-7 shrink-0 items-center justify-center rounded-lg bg-gray-50 sm:w-12" aria-hidden="true">
              <span class="rotate-180 text-[10px] font-semibold uppercase tracking-[0.3em] text-gray-400 [writing-mode:vertical-rl] sm:text-xs">Pasillo</span>
            </div>
            <div class="flex min-w-0 flex-1 gap-1.5 sm:gap-2" :class="i === 0 ? 'flex-row' : 'flex-row-reverse'">
              <div v-if="lado.borde === 'ventana'" class="flex shrink-0 flex-col items-center justify-center gap-1.5 text-sky-600" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 3.75h15v16.5h-15zM12 3.75v16.5M4.5 12h15" />
                </svg>
                <span class="text-[10px] font-semibold uppercase tracking-[0.35em] [writing-mode:vertical-rl]">Ventana</span>
              </div>
              <div class="flex min-w-0 flex-1 flex-col gap-5">
                <div v-for="(tramo, t) in lado.tramos" :key="t" class="flex gap-1.5 sm:gap-2" :class="i === 0 ? 'flex-row' : 'flex-row-reverse'">
                  <!-- La ventana en celeste y la pared en gris, como en la tarjeta del mesón -->
                  <div
                    class="w-1.5 shrink-0 rounded-full"
                    :class="lado.borde === 'ventana' ? 'bg-gradient-to-b from-sky-200 via-sky-400 to-sky-200' : 'bg-gray-300'"
                    :title="lado.borde === 'ventana' ? 'Ventana' : 'Pared'"
                  ></div>
                  <ul class="flex min-w-0 flex-1 flex-col gap-1.5 sm:gap-2">
                    <li v-for="l in tramo" :key="l.lugar.codigo">
                      <button
                        type="button"
                        class="flex min-h-[3.25rem] w-full flex-col justify-center rounded-xl border-2 px-2 py-1.5 text-left transition-all sm:px-2.5"
                        :class="[ESTILOS[l.tipo].caja, seAbre(l.tipo) ? 'cursor-pointer active:scale-[0.98]' : 'cursor-default', coincide(l.lugar.etiqueta, l.sala) ? '' : 'opacity-30']"
                        :title="seAbre(l.tipo) ? `${l.sala?.nombre}: ver horarios de hoy` : TEXTO_NO_SE_ABRE[l.tipo]"
                        :aria-label="`${l.sala?.nombre ?? l.lugar.etiqueta}: ${l.texto}`"
                        @click="seAbre(l.tipo) && l.sala ? (elegidaId = l.sala.id) : undefined"
                      >
                        <!-- El nombre con todo el ancho (Cooperación cabe en el teléfono); el punto va con el estado. -->
                        <span class="truncate font-bold text-gray-900" :class="esNumero(l.lugar.etiqueta) ? 'text-lg leading-none' : 'text-[13px] leading-tight'">
                          {{ l.lugar.etiqueta }}
                        </span>
                        <span class="mt-1 flex items-start gap-1.5 text-[11px] leading-tight" :class="ESTILOS[l.tipo].texto">
                          <span class="mt-[3px] h-2 w-2 shrink-0 rounded-full" :class="ESTILOS[l.tipo].punto"></span>
                          {{ l.texto }}
                        </span>
                      </button>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </template>
        </div>
      </section>
    </div>

    <section v-if="otras.length" class="mt-5 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="font-sans text-sm font-bold text-gray-900">Otras salas</h3>
      <p class="mt-0.5 text-xs text-gray-500">También se reservan, pero no están en el plano.</p>
      <ul class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <li v-for="o in otras" :key="o.sala.id">
          <button
            type="button"
            class="flex min-h-[3.25rem] w-full flex-col justify-center rounded-xl border-2 px-2.5 py-1.5 text-left transition-all"
            :class="[ESTILOS[o.tipo].caja, seAbre(o.tipo) ? 'cursor-pointer active:scale-[0.98]' : 'cursor-default', coincide(o.sala.nombre, o.sala) ? '' : 'opacity-30']"
            :aria-label="`${o.sala.nombre}: ${o.texto}`"
            @click="seAbre(o.tipo) ? (elegidaId = o.sala.id) : undefined"
          >
            <span class="truncate text-[13px] font-bold text-gray-900">{{ o.sala.nombre }}</span>
            <span class="mt-1 flex items-start gap-1.5 text-[11px] leading-tight" :class="ESTILOS[o.tipo].texto">
              <span class="mt-[3px] h-2 w-2 shrink-0 rounded-full" :class="ESTILOS[o.tipo].punto"></span>
              {{ o.texto }}
            </span>
          </button>
        </li>
      </ul>
    </section>

    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-gray-500">
      <div v-for="l in LEYENDA" :key="l.tipo" class="flex items-center gap-1.5">
        <span class="h-3 w-3 rounded border-2" :class="ESTILOS[l.tipo].caja"></span> {{ l.texto }}
      </div>
      <div class="flex items-center gap-1.5"><span class="h-3 w-1.5 rounded-full bg-gradient-to-b from-sky-200 via-sky-400 to-sky-200"></span> Ventana</div>
      <div class="flex items-center gap-1.5"><span class="h-3 w-1.5 rounded-full bg-gray-300"></span> Pared</div>
    </div>

    <Modal :abierto="!!elegida" :titulo="elegida?.nombre ?? ''" :descripcion="elegida ? `Hasta ${elegida.capacidad} personas · hoy` : ''" @cerrar="elegidaId = null">
      <template v-if="elegida">
        <button
          v-if="primeraLibre"
          type="button"
          class="mb-4 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
          @click="reservar(primeraLibre.inicio)"
        >
          Reservar desde las {{ aHora(primeraLibre.inicio) }}
        </button>
        <p v-else class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">No quedan horas libres hoy en esta sala.</p>

        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Lo que queda de hoy</p>
        <ul class="space-y-1.5">
          <li
            v-for="f in diaElegida"
            :key="`${f.tipo}-${f.inicio}`"
            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
            :class="f.tipo === 'libre' ? 'border-emerald-200 bg-emerald-50' : f.tipo === 'mia' ? 'border-indigo-200 bg-indigo-50' : 'border-red-200 bg-red-50'"
          >
            <span class="font-semibold tabular-nums text-gray-900">{{ aHora(f.inicio) }} – {{ aHora(f.fin) }}</span>
            <button
              v-if="f.tipo === 'libre'"
              type="button"
              class="text-sm font-semibold text-emerald-700 hover:text-emerald-900 hover:underline"
              @click="reservar(f.inicio)"
            >
              Reservar
            </button>
            <button
              v-else-if="f.tipo === 'mia' && f.tramo"
              type="button"
              class="text-sm font-semibold text-indigo-700 hover:text-indigo-900 hover:underline"
              @click="cancelar(f.tramo)"
            >
              Tu reserva · {{ meIncluyeron(f.tramo) ? 'salir' : 'cancelar' }}
            </button>
            <span v-else class="text-xs font-semibold text-red-700">Ocupada</span>
          </li>
        </ul>
      </template>
      <template #pie>
        <button
          type="button"
          class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
          @click="elegidaId = null"
        >
          Cerrar
        </button>
      </template>
    </Modal>
  </div>
</template>
