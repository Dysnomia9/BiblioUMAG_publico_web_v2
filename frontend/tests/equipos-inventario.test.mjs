import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { parse, compileScript } from '@vue/compiler-sfc'
import { transformSync } from 'esbuild'
import * as vue from 'vue'

const archivo = new URL('../src/views/EquiposView.vue', import.meta.url)
const descriptor = parse(readFileSync(archivo, 'utf8'), { filename: archivo.pathname }).descriptor
const codigo = transformSync(compileScript(descriptor, { id: 'equipos-inventario' }).content, { loader: 'ts', format: 'cjs' }).code
const esperar = async () => { await vue.nextTick(); await new Promise((resolve) => setTimeout(resolve, 0)) }

function inventario(t, cantidad = 61) {
  const recursos = Array.from({ length: cantidad }, (_, i) => ({
    id: i + 1, codigo_inventario: `Notebook ${String(i + 1).padStart(3, '0')}`,
    codigo_barras: `NB-${String(i + 1).padStart(3, '0')}`, tipo: 'notebook', activo: true, disponible: true,
  }))
  recursos.push({ id: 1001, codigo_inventario: 'Notebook de baja', codigo_barras: 'NB-BAJA', tipo: 'notebook', activo: false, disponible: true })
  const peticiones = []
  const eliminaciones = []
  let rechazarEliminacion = false
  let demorar = false
  let terminarDemorada
  let desmontar
  function respuesta(params) {
    const vigentes = recursos.filter((e) => !e.deleted_at)
    const estado = vigentes.filter((e) => params.activo === undefined || e.activo === Boolean(params.activo))
      .filter((e) => !params.q || `${e.codigo_inventario} ${e.codigo_barras}`.toLowerCase().includes(params.q.toLowerCase()))
    const filtrados = estado.filter((e) => !params.tipo || e.tipo === params.tipo)
    const desde = (params.page - 1) * params.por_pagina
    return { data: {
      data: filtrados.slice(desde, desde + params.por_pagina).map((e) => ({ ...e })),
      current_page: params.page, last_page: Math.max(1, Math.ceil(filtrados.length / params.por_pagina)),
      total: filtrados.length, per_page: params.por_pagina,
      from: filtrados.length ? desde + 1 : null, to: Math.min(desde + params.por_pagina, filtrados.length),
      resumen: { total: vigentes.length, activos: vigentes.filter((e) => e.activo).length, bajas: vigentes.filter((e) => !e.activo).length },
      por_categoria: { notebook: estado.length },
    } }
  }
  const api = {
    get: async (ruta, { params }) => {
      peticiones.push({ ruta, params: { ...params } })
      const resultado = respuesta(params)
      if (demorar) {
        demorar = false
        return new Promise((resolve) => { terminarDemorada = () => resolve(resultado) })
      }
      return resultado
    },
    delete: async (ruta, datos) => {
      eliminaciones.push({ ruta, datos })
      if (rechazarEliminacion) throw new Error('Recurso con préstamo pendiente')
      recursos.find((e) => e.id === Number(ruta.split('/')[2])).deleted_at = '2026-11-18T14:00:00Z'
      return { data: null }
    },
    patch: async (ruta, datos) => {
      const id = Number(ruta.split('/')[2])
      Object.assign(recursos.find((e) => e.id === id), datos)
      return { data: {} }
    },
  }
  const categorias = vue.ref([{ clave: 'notebook', nombre: 'Notebook', activo: true }])
  const modulos = {
    vue: { ...vue, onMounted: () => {}, onUnmounted: (fn) => { desmontar = fn } },
    '@/services/api': { __esModule: true, default: api },
    '@/composables/useToast': { useToast: () => ({ success: () => {}, error: () => {} }), mensajeDeError: (_, mensaje) => mensaje },
    '@/stores/auth': { useAuthStore: () => ({ puede: () => true }) },
    '@/utils/recursos': { useCategoriasRecurso: () => ({ categorias, cargar: async () => {} }), categoriaDe: () => categorias.value[0], COLORES_RECURSO: {}, ICONOS_RECURSO: {} },
    '@/utils/prestamos': { fechaCorta: (v) => v },
  }
  const modulo = { exports: {} }
  vm.runInNewContext(codigo, { module: modulo, exports: modulo.exports, require: (nombre) => modulos[nombre] ?? {}, setTimeout, clearTimeout })
  const f = modulo.exports.default.setup({}, { expose: () => {} })
  t.after(() => desmontar())
  return { f, peticiones, recursos, eliminaciones, rechazarEliminacion: () => { rechazarEliminacion = true }, demorarSiguiente: () => { demorar = true }, resolver: () => terminarDemorada() }
}

test('abre con activos y pide solo 25 recursos, conservando los contadores globales', async (t) => {
  const { f, peticiones } = inventario(t)
  await f.cargar()
  assert.equal(peticiones[0].params.activo, 1)
  assert.equal(peticiones[0].params.por_pagina, 25)
  assert.equal(f.equipos.value.length, 25)
  assert.equal(f.meta.value.total, 61)
  assert.equal(f.resumen.value.activos, 61)
  assert.equal(f.cuantos('notebook'), 61)
})

test('cambiar estado, categoría o tamaño vuelve a la primera página', async (t) => {
  const { f, peticiones } = inventario(t)
  await f.irAPagina(3)
  await esperar()
  f.filtroEstado.value = 'bajas'
  f.filtroCategoria.value = 'notebook'
  await esperar()
  assert.equal(peticiones.at(-1).params.page, 1)
  assert.equal(peticiones.at(-1).params.activo, 0)
  assert.equal(peticiones.at(-1).params.tipo, 'notebook')
  assert.equal(f.equipos.value[0].activo, false)
  f.filtroEstado.value = 'todos'
  f.porPagina.value = 50
  await esperar()
  assert.equal(peticiones.at(-1).params.activo, undefined)
  assert.equal(peticiones.at(-1).params.por_pagina, 50)
  assert.equal(f.equipos.value.length, 50)
})

test('busca en el servidor un recurso que estaba fuera de la página visible', async (t) => {
  const { f, peticiones } = inventario(t)
  await f.cargar()
  f.busqueda.value = 'NB-061'
  await new Promise((resolve) => setTimeout(resolve, 350))
  assert.equal(peticiones.at(-1).params.q, 'NB-061')
  assert.equal(f.equipos.value.length, 1)
  assert.equal(f.equipos.value[0].codigo_barras, 'NB-061')
})

test('dar de baja el último recurso de una página vuelve a una página existente', async (t) => {
  const { f } = inventario(t, 26)
  f.irAPagina(2)
  await esperar()
  assert.equal(f.equipos.value.length, 1)
  f.pedirCambioActivo(f.equipos.value[0])
  f.motivoBaja.value = 'Obsoleto'
  await f.cambiarActivo()
  assert.equal(f.pagina.value, 1)
  assert.equal(f.meta.value.total, 25)
  assert.equal(f.equipos.value.length, 25)
  assert.equal(f.resumen.value.bajas, 2)
})

test('una respuesta atrasada no reemplaza los resultados de un filtro nuevo', async (t) => {
  const { f, demorarSiguiente, resolver } = inventario(t)
  demorarSiguiente()
  const anterior = f.cargar()
  f.filtroEstado.value = 'bajas'
  await esperar()
  resolver()
  await anterior
  assert.equal(f.equipos.value.length, 1)
  assert.equal(f.equipos.value[0].activo, false)
  assert.equal(f.cargando.value, false)
})


test('eliminar exige la baja y la confirmación escrita antes de enviar la solicitud', async (t) => {
  const { f, recursos, eliminaciones } = inventario(t)
  await f.pedirEliminar(recursos[0])
  assert.equal(f.equipoAEliminar.value, null)
  await f.pedirEliminar(recursos.at(-1))
  f.confirmacionEliminar.value = 'si'
  await f.eliminarEquipo()
  assert.equal(eliminaciones.length, 0)
  assert.equal(f.puedeEliminar.value, false)
  f.confirmacionEliminar.value = ' CONFIRMAR '
  await f.eliminarEquipo()
  assert.equal(eliminaciones.length, 1)
  assert.equal(eliminaciones[0].ruta, '/equipos/1001')
  assert.equal(eliminaciones[0].datos.data.confirmacion, 'CONFIRMAR')
  assert.equal(f.equipoAEliminar.value, null)
  assert.equal(f.resumen.value.bajas, 0)
  f.filtroEstado.value = 'todos'
  await esperar()
  assert.equal(f.meta.value.total, 61)
})

test('eliminar el último recurso de la página de bajas vuelve a una página con resultados', async (t) => {
  const { f, recursos } = inventario(t, 25)
  recursos.forEach((e) => { e.activo = false })
  f.filtroEstado.value = 'bajas'
  await esperar()
  f.irAPagina(2)
  await esperar()
  assert.equal(f.equipos.value.length, 1)
  await f.pedirEliminar(f.equipos.value[0])
  f.confirmacionEliminar.value = 'confirmar'
  await f.eliminarEquipo()
  assert.equal(f.pagina.value, 1)
  assert.equal(f.meta.value.total, 25)
  assert.equal(f.equipos.value.length, 25)
})

test('si el servidor rechaza la eliminación, conserva el recurso y la confirmación abierta', async (t) => {
  const { f, recursos, rechazarEliminacion } = inventario(t)
  rechazarEliminacion()
  const equipo = recursos.at(-1)
  await f.pedirEliminar(equipo)
  f.confirmacionEliminar.value = 'confirmar'
  await f.eliminarEquipo()
  assert.equal(f.equipoAEliminar.value.id, equipo.id)
  assert.equal(equipo.deleted_at, undefined)
  assert.equal(f.eliminando.value, false)
})
