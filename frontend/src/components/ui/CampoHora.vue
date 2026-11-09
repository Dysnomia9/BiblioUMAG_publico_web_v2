<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

/**
 * Una hora escrita a mano, con el «:» ya puesto: se teclean los cuatro números seguidos
 * (1835 → 18:35) y el cursor pasa solo de la hora a los minutos. Una hora que empieza con
 * 3 a 9 no puede tener otro dígito: «915» es 09:15. También se puede pegar «18:35». El
 * modelo es «HH:MM» cuando la hora está completa y es válida, o '' mientras falta algo.
 */
const props = withDefaults(defineProps<{ id?: string; invalida?: boolean; etiqueta?: string }>(), { etiqueta: 'Hora' })
const valor = defineModel<string>({ default: '' })

const horas = ref(valor.value.slice(0, 2))
const minutos = ref(valor.value.slice(3, 5))
const campoHoras = ref<HTMLInputElement | null>(null)
const campoMinutos = ref<HTMLInputElement | null>(null)

// Si la hora cambia desde afuera (ya completa), se muestra; mientras se escribe, el modelo va vacío y no se toca lo tecleado.
watch(valor, (nuevo) => {
  if (nuevo && nuevo !== `${horas.value}:${minutos.value}`) {
    horas.value = nuevo.slice(0, 2)
    minutos.value = nuevo.slice(3, 5)
  }
})

/** 25:00 o 18:75: se marca en rojo y el modelo queda vacío. */
const fueraDeRango = computed(() => (horas.value.length === 2 && Number(horas.value) > 23) || (minutos.value.length === 2 && Number(minutos.value) > 59))

function avisar() {
  const completa = horas.value.length === 2 && minutos.value.length === 2 && !fueraDeRango.value
  valor.value = completa ? `${horas.value}:${minutos.value}` : ''
}

async function irAMinutos(seleccionar: boolean) {
  await nextTick()
  const campo = campoMinutos.value
  if (!campo) return
  campo.focus()
  // Con los minutos ya escritos, se seleccionan para escribir encima; si llegaron de corrido, el cursor queda al final.
  if (seleccionar) campo.select()
  else campo.setSelectionRange(campo.value.length, campo.value.length)
}

function escribirHoras(event: Event) {
  const campo = event.target as HTMLInputElement
  let digitos = campo.value.replace(/\D/g, '')
  if (/^[3-9]/.test(digitos)) digitos = `0${digitos}`
  horas.value = digitos.slice(0, 2)
  campo.value = horas.value
  // Tecleado de corrido o pegado entero («1835» o «18:35»): lo que sigue son los minutos.
  if (digitos.length > 2) minutos.value = digitos.slice(2, 4)
  if (digitos.length >= 2) irAMinutos(digitos.length === 2)
  avisar()
}

function escribirMinutos(event: Event) {
  const campo = event.target as HTMLInputElement
  minutos.value = campo.value.replace(/\D/g, '').slice(0, 2)
  campo.value = minutos.value
  avisar()
}

/** Borrar con los minutos vacíos vuelve a la hora. */
function teclaEnMinutos(event: KeyboardEvent) {
  if (event.key === 'Backspace' && !minutos.value) {
    event.preventDefault()
    horas.value = horas.value.slice(0, 1)
    avisar()
    nextTick(() => {
      campoHoras.value?.focus()
      campoHoras.value?.setSelectionRange(horas.value.length, horas.value.length)
    })
  }
}

/** Una hora de un dígito («9») se completa al salir del campo: 09. */
function completarHoras() {
  if (horas.value.length === 1) {
    horas.value = `0${horas.value}`
    avisar()
  }
}

defineExpose({ enfocar: () => campoHoras.value?.focus() })
</script>

<template>
  <div
    class="inline-flex items-center rounded-lg border bg-white px-3 py-2.5 font-mono text-sm focus-within:ring-2 focus-within:ring-indigo-500"
    :class="props.invalida || fueraDeRango ? 'border-red-400' : 'border-gray-300'"
    role="group"
    :aria-label="props.etiqueta"
  >
    <input
      :id="props.id"
      ref="campoHoras"
      :value="horas"
      inputmode="numeric"
      autocomplete="off"
      maxlength="5"
      placeholder="hh"
      aria-label="Hora"
      class="w-6 bg-transparent text-center outline-none placeholder:text-gray-300"
      @input="escribirHoras"
      @blur="completarHoras"
    />
    <span class="text-gray-500" aria-hidden="true">:</span>
    <input
      ref="campoMinutos"
      :value="minutos"
      inputmode="numeric"
      autocomplete="off"
      maxlength="2"
      placeholder="mm"
      aria-label="Minutos"
      class="w-6 bg-transparent text-center outline-none placeholder:text-gray-300"
      @input="escribirMinutos"
      @keydown="teclaEnMinutos"
    />
  </div>
</template>
