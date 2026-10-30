<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { COLORES_RECURSO, ICONOS_RECURSO, textoHoras, useCategoriasRecurso } from '@/utils/recursos'
import type { CategoriaRecurso } from '@/types'

/**
 * Categorías de recursos de apoyo (lo que se presta y no es un libro): cada una aparece
 * como un recuadro en Préstamos → Recursos de apoyo y como opción al registrar un
 * recurso nuevo. No se borran: una categoría que ya no se presta se desactiva.
 */
const toast = useToast()
const { categorias, cargar } = useCategoriasRecurso()

onMounted(() => cargar(true))

type Ficha = { nombre: string; nombre_plural: string; icono: string; color: string; limite_por_persona: string; horas_maximas: string; indicaciones: string }
const fichaVacia = (): Ficha => ({ nombre: '', nombre_plural: '', icono: 'caja', color: 'slate', limite_por_persona: '', horas_maximas: '', indicaciones: '' })

const form = reactive({
  abierto: false,
  categoria: null as CategoriaRecurso | null,
  ficha: fichaVacia(),
  // Mientras no se toque el plural, se sugiere a partir del nombre.
  pluralEditado: false,
  confirmando: false,
  guardando: false,
})

function abrirNueva() {
  Object.assign(form, { abierto: true, categoria: null, ficha: fichaVacia(), pluralEditado: false, confirmando: false })
}

function abrirEdicion(c: CategoriaRecurso) {
  Object.assign(form, {
    abierto: true,
    categoria: c,
    ficha: {
      nombre: c.nombre,
      nombre_plural: c.nombre_plural,
      icono: c.icono,
      color: c.color,
      limite_por_persona: c.limite_por_persona ? String(c.limite_por_persona) : '',
      horas_maximas: c.horas_maximas ? String(c.horas_maximas) : '',
      indicaciones: c.indicaciones ?? '',
    },
    pluralEditado: true,
    confirmando: false,
  })
}

function sugerirPlural(nombre: string) {
  if (form.pluralEditado) return
  const n = nombre.trim()
  form.ficha.nombre_plural = !n ? '' : /[aeiouáéíóú]$/i.test(n) ? `${n}s` : /s$/i.test(n) ? n : `${n}es`
}

const ETIQUETAS: Record<keyof Ficha, string> = {
  nombre: 'Nombre', nombre_plural: 'Plural', icono: 'Ícono', color: 'Color', limite_por_persona: 'Máximo por persona', horas_maximas: 'Tiempo máximo de préstamo', indicaciones: 'Indicaciones',
}

function mostrar(campo: keyof Ficha, f: Ficha): string {
  if (campo === 'icono') return ICONOS_RECURSO[f.icono]?.nombre ?? f.icono
  if (campo === 'color') return COLORES_RECURSO[f.color]?.nombre ?? f.color
  if (campo === 'limite_por_persona') return f.limite_por_persona ? `${f.limite_por_persona} a la vez` : 'sin límite'
  if (campo === 'horas_maximas') return f.horas_maximas ? textoHoras(Number(f.horas_maximas)) : 'sin plazo'
  return f[campo].trim() || '—'
}

const cambios = computed(() => {
  const c = form.categoria
  if (!c) return []
  const antes: Ficha = {
    nombre: c.nombre, nombre_plural: c.nombre_plural, icono: c.icono, color: c.color,
    limite_por_persona: c.limite_por_persona ? String(c.limite_por_persona) : '',
    horas_maximas: c.horas_maximas ? String(c.horas_maximas) : '', indicaciones: c.indicaciones ?? '',
  }
  return (Object.keys(ETIQUETAS) as (keyof Ficha)[])
    .filter((campo) => antes[campo].trim() !== form.ficha[campo].trim())
    .map((campo) => ({ campo: ETIQUETAS[campo], antes: mostrar(campo, antes), despues: mostrar(campo, form.ficha) }))
})

function pedirConfirmacion() {
  const f = form.ficha
  if (!f.nombre.trim() || !f.nombre_plural.trim()) {
    toast.error('Escribe el nombre en singular y en plural (ej. Bicicleta / Bicicletas)')
    return
  }
  if (f.limite_por_persona && !/^\d+$/.test(f.limite_por_persona)) {
    toast.error('El máximo por persona es un número (o déjalo vacío para no poner límite)')
    return
  }
  if (f.horas_maximas && (!/^\d+$/.test(f.horas_maximas) || Number(f.horas_maximas) < 1 || Number(f.horas_maximas) > 720)) {
    toast.error('El tiempo máximo de préstamo va en horas, de 1 a 720 (o déjalo vacío para no poner plazo)')
    return
  }
  if (form.categoria && !cambios.value.length) {
    toast.info('No hay cambios que guardar')
    return
  }
  form.confirmando = true
}

async function guardar() {
  const f = form.ficha
  const datos = {
    nombre: f.nombre.trim(),
    nombre_plural: f.nombre_plural.trim(),
    icono: f.icono,
    color: f.color,
    limite_por_persona: f.limite_por_persona ? Number(f.limite_por_persona) : null,
    horas_maximas: f.horas_maximas ? Number(f.horas_maximas) : null,
    indicaciones: f.indicaciones.trim() || null,
  }
  form.guardando = true
  try {
    if (form.categoria) {
      await api.patch(`/categorias-recurso/${form.categoria.id}`, datos)
      toast.success(`Categoría «${datos.nombre}» actualizada`)
    } else {
      await api.post('/categorias-recurso', datos)
      toast.success(`Categoría «${datos.nombre}» creada: ya aparece en Préstamos → Recursos de apoyo`)
    }
    form.confirmando = false
    form.abierto = false
    await cargar(true)
  } catch (e) {
    form.confirmando = false
    toast.error(mensajeDeError(e, 'No se pudo guardar la categoría'))
  } finally {
    form.guardando = false
  }
}

const aCambiarEstado = ref<CategoriaRecurso | null>(null)
const cambiandoEstado = ref(false)

async function confirmarCambioEstado() {
  const c = aCambiarEstado.value
  if (!c) return
  cambiandoEstado.value = true
  try {
    await api.patch(`/categorias-recurso/${c.id}`, { activo: !c.activo })
    toast.success(c.activo ? `«${c.nombre}» desactivada` : `«${c.nombre}» reactivada`)
    aCambiarEstado.value = null
    await cargar(true)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el estado de la categoría'))
  } finally {
    cambiandoEstado.value = false
  }
}

const fondo = (color: string) => (COLORES_RECURSO[color] ?? COLORES_RECURSO.slate).fondo
const trazo = (icono: string) => (ICONOS_RECURSO[icono] ?? ICONOS_RECURSO.caja).trazo
</script>

<template>
  <div class="bg-white rounded-xl shadow-md p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-4">
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Categorías de recursos de apoyo</h3>
        <p class="text-xs text-gray-500 max-w-xl">
          Lo que se presta y no es un libro: notebooks, audífonos, cargadores, bicicletas… Cada categoría es un recuadro en
          <strong>Préstamos → Recursos de apoyo</strong> y una opción al registrar un recurso en <strong>Recursos de apoyo</strong>.
          Una categoría no se borra: si deja de prestarse, se desactiva y sus recursos y préstamos se conservan.
        </p>
      </div>
      <button type="button" class="shrink-0 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="abrirNueva">
        + Nueva categoría
      </button>
    </div>

    <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
      <div v-for="c in categorias" :key="c.id" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" :class="c.activo ? '' : 'bg-gray-50'">
        <div class="flex min-w-0 items-center gap-3">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="[fondo(c.color), c.activo ? '' : 'opacity-50']">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="trazo(c.icono)" /></svg>
          </span>
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-900">
              {{ c.nombre_plural }}
              <span v-if="!c.activo" class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600">Inactiva</span>
            </p>
            <p class="text-xs text-gray-500">
              {{ c.recursos_activos ?? 0 }} {{ c.recursos_activos === 1 ? 'recurso' : 'recursos' }} ({{ c.recursos_disponibles ?? 0 }} libres)
              · {{ c.limite_por_persona ? `máximo ${c.limite_por_persona} por persona` : 'sin límite por persona' }}
              · {{ c.horas_maximas ? `se presta por ${textoHoras(c.horas_maximas)}` : 'sin plazo de devolución' }}
              <template v-if="c.indicaciones"> · {{ c.indicaciones }}</template>
            </p>
          </div>
        </div>
        <div class="flex shrink-0 items-center gap-3 text-sm">
          <button type="button" class="font-medium text-indigo-700 hover:text-indigo-800" @click="abrirEdicion(c)">Editar</button>
          <button
            type="button"
            class="font-medium"
            :class="c.activo ? 'text-red-600 hover:text-red-700' : 'text-emerald-700 hover:text-emerald-800'"
            @click="aCambiarEstado = c"
          >
            {{ c.activo ? 'Desactivar' : 'Reactivar' }}
          </button>
        </div>
      </div>
      <p v-if="!categorias.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin categorías todavía.</p>
    </div>

    <Modal
      :abierto="form.abierto"
      :titulo="form.categoria ? `Editar «${form.categoria.nombre}»` : 'Nueva categoría de recursos de apoyo'"
      :descripcion="form.categoria ? 'Los recursos y préstamos que ya tiene no cambian.' : 'Ej: Bicicleta, Calculadora científica, Tablet, Casillero.'"
      ancho="lg"
      pie-fijo
      :bloqueado="form.guardando"
      @cerrar="form.abierto = false"
    >
      <div class="space-y-4 text-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="cat-nombre" class="mb-1 block text-xs font-medium text-gray-600">Nombre (singular)</label>
            <input
              id="cat-nombre"
              v-model="form.ficha.nombre"
              maxlength="100"
              placeholder="Bicicleta"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
              @input="sugerirPlural(form.ficha.nombre)"
            />
          </div>
          <div>
            <label for="cat-plural" class="mb-1 block text-xs font-medium text-gray-600">Nombre en plural</label>
            <input
              id="cat-plural"
              v-model="form.ficha.nombre_plural"
              maxlength="100"
              placeholder="Bicicletas"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
              @input="form.pluralEditado = true"
            />
          </div>
        </div>

        <div>
          <p class="mb-1 text-xs font-medium text-gray-600">Ícono</p>
          <div class="grid grid-cols-6 gap-1.5 sm:grid-cols-11" role="radiogroup" aria-label="Ícono">
            <button
              v-for="(icono, clave) in ICONOS_RECURSO"
              :key="clave"
              type="button"
              role="radio"
              :aria-checked="form.ficha.icono === clave"
              :title="icono.nombre"
              class="flex h-10 items-center justify-center rounded-lg ring-1 transition"
              :class="form.ficha.icono === clave ? 'bg-indigo-50 text-indigo-700 ring-2 ring-indigo-500' : 'text-gray-500 ring-gray-200 hover:bg-gray-50'"
              @click="form.ficha.icono = clave"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="icono.trazo" /></svg>
              <span class="sr-only">{{ icono.nombre }}</span>
            </button>
          </div>
        </div>

        <div>
          <p class="mb-1 text-xs font-medium text-gray-600">Color</p>
          <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Color">
            <button
              v-for="(color, clave) in COLORES_RECURSO"
              :key="clave"
              type="button"
              role="radio"
              :aria-checked="form.ficha.color === clave"
              :title="color.nombre"
              class="h-8 w-8 rounded-full ring-offset-2 transition"
              :class="[color.punto, form.ficha.color === clave ? 'ring-2 ring-gray-900 dark:ring-white' : 'hover:scale-110']"
              @click="form.ficha.color = clave"
            >
              <span class="sr-only">{{ color.nombre }}</span>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[10rem_12rem_1fr]">
          <div>
            <label for="cat-limite" class="mb-1 block text-xs font-medium text-gray-600">Máximo por persona</label>
            <input
              id="cat-limite"
              v-model="form.ficha.limite_por_persona"
              inputmode="numeric"
              maxlength="2"
              placeholder="Sin límite"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label for="cat-horas" class="mb-1 block text-xs font-medium text-gray-600">Tiempo máximo (horas)</label>
            <input
              id="cat-horas"
              v-model="form.ficha.horas_maximas"
              inputmode="numeric"
              maxlength="3"
              placeholder="Sin plazo"
              aria-describedby="cat-horas-ayuda"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
            />
            <p id="cat-horas-ayuda" class="mt-1 text-[11px] text-gray-500">Al prestar, la devolución queda a esa hora.</p>
          </div>
          <div>
            <label for="cat-indicaciones" class="mb-1 block text-xs font-medium text-gray-600">Indicaciones al prestar (opcional)</label>
            <input
              id="cat-indicaciones"
              v-model="form.ficha.indicaciones"
              maxlength="255"
              placeholder="Ej: se devuelve el mismo día, con su casco y candado"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div>
          <p class="mb-1 text-xs font-medium text-gray-600">Así se verá en Préstamos</p>
          <div class="flex items-start gap-3 rounded-2xl border border-gray-200 p-4">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="fondo(form.ficha.color)">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="trazo(form.ficha.icono)" /></svg>
            </span>
            <div>
              <p class="font-semibold text-gray-900">{{ form.ficha.nombre_plural || 'Nombre en plural' }}</p>
              <p class="text-xs text-gray-500">
                {{ form.ficha.indicaciones || 'Se devuelve al término de la estadía' }}<template v-if="form.ficha.horas_maximas"> · hasta {{ textoHoras(Number(form.ficha.horas_maximas)) }}</template><template v-if="form.ficha.limite_por_persona"> · máximo {{ form.ficha.limite_por_persona }} por persona</template>
              </p>
            </div>
          </div>
        </div>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          :disabled="form.guardando"
          @click="form.abierto = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
          :disabled="form.guardando"
          @click="pedirConfirmacion"
        >
          {{ form.categoria ? 'Revisar cambios' : 'Revisar y crear' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="form.confirmando"
      :titulo="form.categoria ? '¿Guardar los cambios de la categoría?' : '¿Crear esta categoría?'"
      :confirmar="form.categoria ? 'Sí, guardar' : 'Sí, crear'"
      :cargando="form.guardando"
      @confirmar="guardar"
      @cancelar="form.confirmando = false"
    >
      <ul v-if="form.categoria" class="space-y-1.5">
        <li v-for="c in cambios" :key="c.campo">
          {{ c.campo }}: <span class="line-through">{{ c.antes }}</span> → <strong class="text-gray-900">{{ c.despues }}</strong>
        </li>
      </ul>
      <template v-else>
        <p>
          Se creará <strong class="text-gray-900">{{ form.ficha.nombre.trim() }}</strong> ({{ form.ficha.nombre_plural.trim() }}),
          {{ form.ficha.limite_por_persona ? `máximo ${form.ficha.limite_por_persona} por persona` : 'sin límite por persona' }},
          {{ form.ficha.horas_maximas ? `se presta por ${textoHoras(Number(form.ficha.horas_maximas))}` : 'sin plazo de devolución' }}.
        </p>
        <p class="mt-2">Aparecerá de inmediato en Préstamos → Recursos de apoyo; después hay que registrar sus recursos (con su código de barras) en Recursos de apoyo.</p>
      </template>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!aCambiarEstado"
      :titulo="aCambiarEstado?.activo ? '¿Desactivar esta categoría?' : '¿Reactivar esta categoría?'"
      :confirmar="aCambiarEstado?.activo ? 'Sí, desactivar' : 'Sí, reactivar'"
      :tono="aCambiarEstado?.activo ? 'peligro' : 'exito'"
      :cargando="cambiandoEstado"
      @confirmar="confirmarCambioEstado"
      @cancelar="aCambiarEstado = null"
    >
      <p v-if="aCambiarEstado?.activo">
        <strong class="text-gray-900">{{ aCambiarEstado.nombre_plural }}</strong> dejará de aparecer en Préstamos y no se podrán registrar recursos nuevos de
        esta categoría. Lo que esté prestado se sigue pudiendo devolver, y el historial se conserva.
      </p>
      <p v-else><strong class="text-gray-900">{{ aCambiarEstado?.nombre_plural }}</strong> volverá a aparecer en Préstamos → Recursos de apoyo.</p>
    </DialogoConfirmacion>
  </div>
</template>
