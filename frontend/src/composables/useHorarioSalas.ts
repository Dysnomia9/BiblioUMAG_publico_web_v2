import { computed, type Ref } from 'vue'
import { diaEnPlural } from '@/utils/horario'

function aMinutos(hhmm: string) {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

/**
 * Geometría de la línea de tiempo de salas y el horario del día. La línea va de `apertura`
 * a `cierre` (lo más amplio de la semana); `atencion` es lo que atiende la biblioteca ese
 * día (null si no abre): lo de antes y lo de después se raya y no se reserva.
 */
export function useHorarioSalas(o: {
  apertura: Ref<string>
  cierre: Ref<string>
  atencion: Ref<[string, string] | null>
  granularidad: Ref<number>
  fecha: Ref<string>
}) {
  const aperturaMin = computed(() => aMinutos(o.apertura.value))
  const cierreMin = computed(() => aMinutos(o.cierre.value))
  const totalMin = computed(() => cierreMin.value - aperturaMin.value)
  const abreMin = computed(() => (o.atencion.value ? aMinutos(o.atencion.value[0]) : cierreMin.value))
  const cierraMin = computed(() => (o.atencion.value ? aMinutos(o.atencion.value[1]) : aperturaMin.value))
  /** Primer inicio que se puede agendar: la apertura llevada a la granularidad (08:45 → 09:00). */
  const primerInicioMin = computed(() => Math.ceil(abreMin.value / o.granularidad.value) * o.granularidad.value)

  function pctMin(min: number) {
    return ((min - aperturaMin.value) / totalMin.value) * 100
  }
  const acotar = (n: number) => Math.min(100, Math.max(0, n))

  const pctAntesDeAbrir = computed(() => acotar(pctMin(abreMin.value)))
  const pctDesdeCierre = computed(() => acotar(pctMin(cierraMin.value)))

  /** Horas pares rotuladas arriba de la línea, sin salirse de sus bordes. */
  const horasRotuladas = computed(() => {
    const horas: number[] = []
    for (let h = Math.ceil(aperturaMin.value / 60); h * 60 <= cierreMin.value; h++) if (h % 2 === 0) horas.push(h)
    return horas
  })

  const atiende = (min: number) => !!o.atencion.value && min >= abreMin.value && min < cierraMin.value
  /** El día atiende menos de lo que muestra la línea (el sábado, hasta las 14:00). */
  const horarioReducido = computed(() => !!o.atencion.value && (abreMin.value > aperturaMin.value || cierraMin.value < cierreMin.value))
  const textoHorario = computed(() => (o.atencion.value ? `${o.atencion.value[0]} – ${o.atencion.value[1]}` : 'sin atención'))
  const textoFueraDeHorario = computed(() => {
    if (!o.atencion.value) return ''
    const dias = diaEnPlural(new Date(`${o.fecha.value}T12:00:00`))
    return `Los ${dias} la biblioteca atiende de ${o.atencion.value[0]} a ${o.atencion.value[1]}: fuera de ese horario no se reservan salas.`
  })

  /** Un tramo dentro de la línea: posición y ancho en %, recortado a sus bordes. */
  function posicionTramo(inicio: string, fin: string) {
    const izquierda = acotar(pctMin(aMinutos(inicio)))
    const derecha = acotar(pctMin(aMinutos(fin)))
    return { izquierda, ancho: Math.max(derecha - izquierda, 2.5), visible: derecha > 0 && izquierda < 100 }
  }

  return {
    aperturaMin,
    cierreMin,
    totalMin,
    abreMin,
    cierraMin,
    primerInicioMin,
    pctAntesDeAbrir,
    pctDesdeCierre,
    horasRotuladas,
    atiende,
    horarioReducido,
    textoHorario,
    textoFueraDeHorario,
    posicionTramo,
  }
}
