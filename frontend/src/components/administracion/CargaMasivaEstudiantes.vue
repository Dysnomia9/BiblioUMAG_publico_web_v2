<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { construirCsv, descargarCsv } from '@/utils/csv'
import { describirDocumento } from '@/utils/documento'
import { fechaVigencia, proximoVencimiento } from '@/utils/vigencia'
import { COLUMNAS_PLANTILLA, descargarPlantilla, leerPlanilla, type FilaPlanilla } from '@/utils/planilla'
import type { EstadoFilaImportacion, FilaImportacion, ResultadoImportacion } from '@/types'

/**
 * Carga masiva en tres pasos: elegir el archivo, revisar qué pasará con cada fila (el
 * servidor dice quién es nuevo, a quién ya existe se le actualizan los datos y qué
 * cambia, quién ya está al día, qué filas se repiten o tienen errores) y confirmar de
 * forma explícita. Nada se escribe hasta el último paso.
 */
const props = defineProps<{ abierto: boolean }>()
const emit = defineEmits<{ 'update:abierto': [valor: boolean]; cargado: [] }>()

const toast = useToast()

type Paso = 'elegir' | 'revision' | 'resultado'
const paso = ref<Paso>('elegir')
const archivo = ref('')
const filas = ref<FilaPlanilla[]>([])
const ignoradas = ref<string[]>([])
const revision = ref<ResultadoImportacion | null>(null)
const resultado = ref<ResultadoImportacion | null>(null)
const procesando = ref(false)
const error = ref('')
const confirmado = ref(false)
const filtro = ref<EstadoFilaImportacion | 'todas'>('todas')

watch(
  () => props.abierto,
  (abierto) => {
    if (abierto) reiniciar()
  },
)

function reiniciar() {
  paso.value = 'elegir'
  archivo.value = ''
  filas.value = []
  ignoradas.value = []
  revision.value = null
  resultado.value = null
  error.value = ''
  confirmado.value = false
  filtro.value = 'todas'
}

function cerrar() {
  if (!procesando.value) emit('update:abierto', false)
}

async function elegirArchivo(evento: Event) {
  const input = evento.target as HTMLInputElement
  const elegido = input.files?.[0]
  input.value = ''
  if (!elegido) return

  error.value = ''
  procesando.value = true
  try {
    const lectura = await leerPlanilla(elegido)
    if (!lectura.filas.length) throw new Error('El archivo no tiene filas con datos debajo de los encabezados.')
    if (lectura.filas.length > 10000) throw new Error(`El archivo tiene ${lectura.filas.length} filas: el máximo por carga es 10.000. Divídelo en partes.`)

    const { data } = await api.post<ResultadoImportacion>('/usuarios/importar/revisar', { filas: lectura.filas })
    archivo.value = elegido.name
    filas.value = lectura.filas
    ignoradas.value = lectura.ignoradas
    revision.value = data
    confirmado.value = false
    filtro.value = data.resumen.nuevos === data.resumen.total ? 'nuevo' : 'todas'
    paso.value = 'revision'
  } catch (e) {
    error.value = e instanceof Error && !(e as any).response ? e.message : mensajeDeError(e, 'No se pudo revisar el archivo')
  } finally {
    procesando.value = false
  }
}

const resumen = computed(() => revision.value?.resumen ?? { total: 0, nuevos: 0, actualizados: 0, sin_cambios: 0, duplicados: 0, invalidos: 0 })
const omitidas = computed(() => resumen.value.duplicados + resumen.value.invalidos)
const porGuardar = computed(() => resumen.value.nuevos + resumen.value.actualizados)

/** Cuántos de los que ya existían estaban inactivos y quedan activos con la carga. */
const habilitadosEn = (r: ResultadoImportacion | null) => (r?.filas ?? []).filter((f) => f.estado === 'actualizar' && f.cambios.activo).length
const habilitados = computed(() => habilitadosEn(revision.value))

const ESTADOS: Record<EstadoFilaImportacion, { texto: string; hecho: string; badge: string; tarjeta: string; nota: string; notaHecho: string }> = {
  nuevo: {
    texto: 'Se cargará', hecho: 'Cargado', badge: 'bg-emerald-100 text-emerald-700', tarjeta: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    nota: 'nuevos: se cargarán', notaHecho: 'cargados',
  },
  actualizar: {
    texto: 'Se actualizará', hecho: 'Actualizado', badge: 'bg-amber-100 text-amber-800', tarjeta: 'border-amber-200 bg-amber-50 text-amber-800',
    nota: 'ya existen: se actualizarán', notaHecho: 'actualizados',
  },
  sin_cambios: {
    texto: 'Sin cambios', hecho: 'Sin cambios', badge: 'bg-gray-100 text-gray-700', tarjeta: 'border-gray-200 bg-gray-50 text-gray-700',
    nota: 'ya existen y están al día', notaHecho: 'ya estaban al día',
  },
  duplicado: {
    texto: 'Repetido en el archivo', hecho: 'Repetido en el archivo', badge: 'bg-orange-100 text-orange-800', tarjeta: 'border-orange-200 bg-orange-50 text-orange-800',
    nota: 'repetidos en el archivo', notaHecho: 'repetidos en el archivo',
  },
  invalido: {
    texto: 'Con errores', hecho: 'Con errores', badge: 'bg-red-100 text-red-700', tarjeta: 'border-red-200 bg-red-50 text-red-700',
    nota: 'con errores: no se cargarán', notaHecho: 'con errores: no se cargaron',
  },
}

/** Ya hecha la carga, «Se cargará» pasa a «Cargado» y «Se actualizará» a «Actualizado». */
const textoEstado = (estado: EstadoFilaImportacion) => (paso.value === 'resultado' ? ESTADOS[estado].hecho : ESTADOS[estado].texto)
const notaEstado = (estado: EstadoFilaImportacion) => (paso.value === 'resultado' ? ESTADOS[estado].notaHecho : ESTADOS[estado].nota)

// En el resultado, los números son los de la carga real (el servidor vuelve a revisar al cargar).
const tarjetas = computed(() => {
  const r = (paso.value === 'resultado' ? resultado.value?.resumen : null) ?? resumen.value
  return [
    { estado: 'nuevo' as const, valor: r.nuevos },
    { estado: 'actualizar' as const, valor: r.actualizados },
    { estado: 'sin_cambios' as const, valor: r.sin_cambios },
    { estado: 'duplicado' as const, valor: r.duplicados },
    { estado: 'invalido' as const, valor: r.invalidos },
  ]
})

const CAMPOS: Record<string, string> = {
  nombre: 'Nombre', apellido: 'Apellido', email: 'Correo', tipo: 'Tipo', carrera: 'Carrera', anio_ingreso: 'Año de ingreso', sexo: 'Sexo', pais: 'País', activo: 'Estado', vigente_hasta: 'Vigente hasta',
}

/** Null si no había dato (se muestra «sin dato»). */
function valorCambio(campo: string, valor: unknown): string | null {
  if (campo === 'activo') return valor ? 'Activo' : 'Inactivo'
  if (campo === 'vigente_hasta') return valor ? fechaVigencia(String(valor)) : null
  if (valor === null || valor === undefined || valor === '') return null
  const texto = String(valor)
  return campo === 'tipo' ? texto.charAt(0).toUpperCase() + texto.slice(1) : texto
}

/** Lo que cambia en quien ya existe, para mostrarlo «antes → después». */
function cambiosDe(f: FilaImportacion) {
  return (Object.entries(f.cambios ?? {}) as [string, [unknown, unknown]][]).map(([campo, [antes, despues]]) => ({
    campo: CAMPOS[campo] ?? campo,
    antes: valorCambio(campo, antes),
    despues: valorCambio(campo, despues),
  }))
}

const textoBoton = computed(() => {
  const { nuevos, actualizados } = resumen.value
  if (nuevos && actualizados) return `Cargar ${nuevos} y actualizar ${actualizados}`
  if (nuevos) return `Confirmar y cargar ${nuevos}`
  if (actualizados) return `Confirmar y actualizar ${actualizados}`
  return 'Nada que cargar'
})

const fraseResultado = computed(() => {
  const creados = resultado.value?.creados ?? 0
  const actualizados = resultado.value?.actualizados ?? 0
  const activos = habilitadosEn(resultado.value)
  const frase =
    [
      creados ? `se ${creados === 1 ? 'cargó 1 estudiante nuevo' : `cargaron ${creados} estudiantes nuevos`}` : '',
      actualizados
        ? `se ${actualizados === 1 ? 'actualizó 1 persona que ya existía' : `actualizaron ${actualizados} personas que ya existían`}` +
          (activos ? ` (${activos === 1 ? '1 quedó activa' : `${activos} quedaron activas`})` : '')
        : '',
    ]
      .filter(Boolean)
      .join(' y ') || 'no se cargó ni actualizó a nadie'
  return frase.charAt(0).toUpperCase() + frase.slice(1)
})

const filasVisibles = computed(() => {
  const todas = (paso.value === 'resultado' ? resultado.value : revision.value)?.filas ?? []
  return filtro.value === 'todas' ? todas : todas.filter((f) => f.estado === filtro.value)
})

async function cargar() {
  if (!confirmado.value || !porGuardar.value) return
  procesando.value = true
  try {
    const { data } = await api.post<ResultadoImportacion>('/usuarios/importar', { filas: filas.value, confirmado: true, archivo: archivo.value })
    resultado.value = data
    paso.value = 'resultado'
    filtro.value = 'todas'
    toast.success(fraseResultado.value)
    emit('cargado')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo hacer la carga'))
  } finally {
    procesando.value = false
  }
}

/** Detalle fila por fila (para corregir en el archivo las que no se cargaron). */
function descargarDetalle() {
  const datos = (paso.value === 'resultado' ? resultado.value : revision.value)?.filas ?? []
  descargarCsv(
    `revision-carga-${archivo.value.replace(/\.[^.]+$/, '') || 'estudiantes'}.csv`,
    construirCsv([
      {
        titulo: `Carga masiva de estudiantes — ${archivo.value}`,
        columnas: ['Fila del archivo', 'Resultado', 'RUT o documento', 'Nombre', 'Apellido', 'Carrera', 'Detalle'],
        filas: datos.map((f: FilaImportacion) => [
          f.fila,
          textoEstado(f.estado),
          f.datos.rut ?? '',
          f.datos.nombre ?? '',
          f.datos.apellido ?? '',
          f.datos.carrera ?? '',
          [...f.mensajes, ...cambiosDe(f).map((c) => `${c.campo}: ${c.antes ?? 'sin dato'} → ${c.despues}.`)].join(' '),
        ]),
      },
    ]),
  )
}
</script>

<template>
  <Modal :abierto="abierto" titulo="Carga masiva de estudiantes" ancho="xl" pie-fijo :bloqueado="procesando" @cerrar="cerrar">
    <!-- Paso 1: elegir el archivo -->
    <div v-if="paso === 'elegir'" class="space-y-4 text-sm">
      <ol class="flex flex-wrap gap-2 text-xs font-medium text-gray-500">
        <li class="rounded-full bg-indigo-600 px-3 py-1 text-white">1. Elegir archivo</li>
        <li class="rounded-full bg-gray-100 px-3 py-1">2. Revisar</li>
        <li class="rounded-full bg-gray-100 px-3 py-1">3. Confirmar</li>
      </ol>
      <p class="text-gray-600">
        Sube una planilla <strong>Excel (.xlsx)</strong> o <strong>CSV</strong> con una fila por estudiante. Antes de guardar nada, el sistema te
        mostrará quiénes son nuevos y quiénes <strong>ya existen</strong>: a ellos se les completan o corrigen los datos con los de la planilla (lo
        que venga vacío no borra nada) y <strong>se renueva su cuenta</strong> hasta el {{ fechaVigencia(proximoVencimiento()) }} (a los que no vienen en la planilla no se les toca nada: vencen solos en su fecha). Verás qué cambia en cada uno y tendrás que confirmar.
      </p>
      <p class="rounded-lg bg-indigo-50/60 px-3 py-2 text-indigo-950 ring-1 ring-indigo-100">
        <strong>¿Tienes el reporte de matrícula de pregrado de la universidad?</strong> Súbelo tal cual (Excel o CSV, también el guardado en Mac). Se
        toman solo el RUT, el nombre, los apellidos (Paterno y Materno), el sexo, la nacionalidad (para reconocer pasaportes y DNI extranjeros), la
        carrera con su especialidad, el año de ingreso y el correo si viene; el resto de las columnas se ignora. Los nombres en mayúsculas se guardan
        como nombre propio.
      </p>
      <div class="rounded-lg border border-gray-200">
        <p class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-600">Columnas</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="c in COLUMNAS_PLANTILLA" :key="c.campo" class="grid grid-cols-1 gap-1 px-4 py-2 sm:grid-cols-[10rem_1fr]">
            <span class="font-medium text-gray-900">{{ c.encabezado }}</span>
            <span class="text-gray-600">{{ c.nota }}</span>
          </li>
        </ul>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-600">Descargar plantilla:</span>
        <button type="button" class="rounded-lg border border-emerald-300 px-3 py-1.5 font-medium text-emerald-700 hover:bg-emerald-50" @click="descargarPlantilla('excel')">Excel</button>
        <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-50" @click="descargarPlantilla('csv')">CSV</button>
      </div>
      <label
        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-indigo-200 bg-indigo-50/40 px-4 py-8 text-center transition hover:border-indigo-400"
        :class="procesando ? 'pointer-events-none opacity-60' : ''"
      >
        <svg class="h-8 w-8 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
        </svg>
        <span class="font-semibold text-indigo-800">{{ procesando ? 'Revisando el archivo…' : 'Elegir archivo (.xlsx o .csv)' }}</span>
        <span class="text-xs text-gray-500">Hasta 10.000 filas por carga</span>
        <input type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sr-only" @change="elegirArchivo" />
      </label>
      <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-red-700" role="alert">{{ error }}</p>
    </div>

    <!-- Paso 2 y 3: revisión y resultado -->
    <div v-else class="space-y-4 text-sm">
      <ol class="flex flex-wrap gap-2 text-xs font-medium text-gray-500">
        <li class="rounded-full bg-gray-100 px-3 py-1">1. Elegir archivo</li>
        <li class="rounded-full px-3 py-1" :class="paso === 'revision' ? 'bg-indigo-600 text-white' : 'bg-gray-100'">2. Revisar</li>
        <li class="rounded-full px-3 py-1" :class="paso === 'resultado' ? 'bg-emerald-600 text-white' : 'bg-gray-100'">3. Confirmar</li>
      </ol>

      <div v-if="paso === 'resultado' && resultado" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
        <p class="font-semibold">{{ fraseResultado }} desde «{{ archivo }}».</p>
        <p v-if="revision && (resultado.creados ?? 0) < revision.resumen.nuevos" class="mt-1 text-xs">
          {{ revision.resumen.nuevos - (resultado.creados ?? 0) }} de los que estaban como nuevos ya los había registrado otra persona entre la revisión y
          la carga: no se duplicaron.
        </p>
        <p class="mt-1 text-xs">
          La carga quedó en el Registro absoluto con tu nombre{{ resultado.actualizados ? ', con lo que cambió en cada persona' : '' }}.
        </p>
      </div>
      <p v-else class="text-gray-600">
        Archivo <strong class="text-gray-900">{{ archivo }}</strong>: {{ resumen.total }} {{ resumen.total === 1 ? 'fila' : 'filas' }}. Todavía no se ha guardado nada.
      </p>

      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <button
          v-for="t in tarjetas"
          :key="t.estado"
          type="button"
          class="rounded-xl border px-3 py-2.5 text-left transition"
          :class="[ESTADOS[t.estado].tarjeta, filtro === t.estado ? 'ring-2 ring-offset-1 ring-indigo-400' : '']"
          @click="filtro = filtro === t.estado ? 'todas' : t.estado"
        >
          <span class="block text-2xl font-bold tabular-nums">{{ t.valor }}</span>
          <span class="block text-xs font-medium">{{ notaEstado(t.estado) }}</span>
        </button>
      </div>

      <div v-if="paso === 'revision'" class="space-y-2">
        <p v-if="resumen.actualizados" class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900 ring-1 ring-amber-200">
          <strong>{{ resumen.actualizados }} {{ resumen.actualizados === 1 ? 'persona ya existe' : 'personas ya existen' }}</strong> en el sistema (mismo
          RUT o documento): <strong>se actualizarán sus datos con los de la planilla</strong>
          <template v-if="habilitados">
            y {{ habilitados === 1 ? 'la que estaba inactiva quedará activa' : `las ${habilitados} que estaban inactivas quedarán activas` }} (cuentas renovadas hasta el {{ fechaVigencia(proximoVencimiento()) }})</template
          >. Lo que en la planilla viene vacío no borra nada; abajo se ve qué cambia en cada una.
        </p>
        <p v-if="resumen.duplicados" class="rounded-lg bg-orange-50 px-3 py-2 text-orange-900 ring-1 ring-orange-200">
          {{ resumen.duplicados }} {{ resumen.duplicados === 1 ? 'fila repite' : 'filas repiten' }} un RUT o documento que ya aparece más arriba en el
          archivo: se considera solo la primera.
        </p>
        <p v-if="resumen.invalidos" class="rounded-lg bg-red-50 px-3 py-2 text-red-800 ring-1 ring-red-200">
          {{ resumen.invalidos }} {{ resumen.invalidos === 1 ? 'fila tiene' : 'filas tienen' }} errores y no se cargará{{ resumen.invalidos === 1 ? '' : 'n' }}.
          Puedes descargar el detalle, corregirlas en el archivo y subirlo otra vez: las que ya se hayan cargado aparecerán como «Sin cambios».
        </p>
        <p v-if="ignoradas.length" class="text-xs text-gray-500">Columnas que no se usan: {{ ignoradas.join(', ') }}.</p>
      </div>

      <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
              <th class="px-3 py-2">Fila</th>
              <th class="px-3 py-2">Documento</th>
              <th class="px-3 py-2">Nombre</th>
              <th class="px-3 py-2 hidden md:table-cell">Carrera</th>
              <th class="px-3 py-2">Resultado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="f in filasVisibles.slice(0, 300)" :key="f.fila" class="align-top">
              <td class="px-3 py-2 tabular-nums text-gray-500">{{ f.fila }}</td>
              <td class="px-3 py-2 font-mono text-xs text-gray-700 whitespace-nowrap">
                {{ f.datos.rut ? describirDocumento(f.datos.rut, f.datos.tipo_documento, f.datos.pais) : '—' }}
              </td>
              <td class="px-3 py-2 text-gray-900">{{ [f.datos.nombre, f.datos.apellido].filter(Boolean).join(' ') || '—' }}</td>
              <td class="px-3 py-2 text-gray-600 hidden md:table-cell">{{ f.datos.carrera ?? '—' }}</td>
              <td class="px-3 py-2">
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="ESTADOS[f.estado].badge">{{ textoEstado(f.estado) }}</span>
                <p v-for="(m, i) in f.mensajes" :key="i" class="mt-1 text-xs text-gray-600">{{ m }}</p>
                <ul v-if="f.estado === 'actualizar'" class="mt-1 space-y-0.5 text-xs text-gray-600">
                  <li v-for="c in cambiosDe(f)" :key="c.campo">
                    {{ c.campo }}: <span v-if="c.antes" class="line-through">{{ c.antes }}</span><span v-else class="italic text-gray-400">sin dato</span>
                    → <strong class="font-medium text-gray-900">{{ c.despues }}</strong>
                  </li>
                </ul>
              </td>
            </tr>
            <tr v-if="!filasVisibles.length">
              <td colspan="5" class="px-3 py-6 text-center text-gray-400">Sin filas en este grupo.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="filasVisibles.length > 300" class="text-xs text-gray-500">
        Se muestran las primeras 300 de {{ filasVisibles.length }}. El detalle completo está en «Descargar detalle».
      </p>

      <label v-if="paso === 'revision' && porGuardar" class="flex items-start gap-2.5 rounded-lg border border-indigo-200 bg-indigo-50/60 px-3 py-3 text-indigo-950">
        <input v-model="confirmado" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
        <span>
          Revisé el resumen y confirmo
          <template v-if="resumen.nuevos">
            que se carguen <strong>{{ resumen.nuevos }} {{ resumen.nuevos === 1 ? 'estudiante nuevo' : 'estudiantes nuevos' }}</strong></template
          ><template v-if="resumen.nuevos && resumen.actualizados"> y</template>
          <template v-if="resumen.actualizados">
            que se actualicen los datos de
            <strong>{{ resumen.actualizados }} {{ resumen.actualizados === 1 ? 'persona que ya existe' : 'personas que ya existen' }}</strong>
            <template v-if="habilitados"> ({{ habilitados === 1 ? '1 quedará activa' : `${habilitados} quedarán activas` }})</template></template
          ><template v-if="omitidas">, sabiendo que {{ omitidas }} {{ omitidas === 1 ? 'fila no se cargará' : 'filas no se cargarán' }}</template>.
        </span>
      </label>
      <p v-else-if="paso === 'revision'" class="rounded-lg bg-gray-50 px-3 py-2 text-gray-700">
        No hay estudiantes nuevos ni datos que actualizar en este archivo.
      </p>
    </div>

    <template #pie>
      <template v-if="paso === 'elegir'">
        <button type="button" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" @click="cerrar">
          Cancelar
        </button>
      </template>
      <template v-else-if="paso === 'revision'">
        <button type="button" class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" :disabled="procesando" @click="reiniciar">
          Elegir otro archivo
        </button>
        <button type="button" class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" :disabled="procesando" @click="descargarDetalle">
          Descargar detalle
        </button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-50"
          :disabled="procesando || !confirmado || !porGuardar"
          @click="cargar"
        >
          {{ procesando ? 'Cargando…' : textoBoton }}
        </button>
      </template>
      <template v-else>
        <button type="button" class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" @click="descargarDetalle">
          Descargar detalle
        </button>
        <button type="button" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="cerrar">
          Listo
        </button>
      </template>
    </template>
  </Modal>
</template>
