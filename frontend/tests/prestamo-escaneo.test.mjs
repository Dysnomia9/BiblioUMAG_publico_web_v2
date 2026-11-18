import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { parse, compileScript } from '@vue/compiler-sfc'
import { transformSync } from 'esbuild'
import * as vue from 'vue'

// Ejecuta el setup real del formulario con una API ficticia. Ninguna prueba toca préstamos reales.
const archivo = new URL('../src/views/PrestamoView.vue', import.meta.url)
const descriptor = parse(readFileSync(archivo, 'utf8'), { filename: archivo.pathname }).descriptor
const codigo = transformSync(compileScript(descriptor, { id: 'prestamo-escaneo' }).content, { loader: 'ts', format: 'cjs' }).code

function formulario() {
  const categoria = { id: 1, clave: 'notebook', nombre: 'Notebook', nombre_plural: 'Notebooks', activo: true, limite_por_persona: null }
  const equipo = { id: 1, tipo: 'notebook', codigo_barras: '30000003222737', codigo_inventario: 'PC de prueba', activo: true, disponible: true }
  const categorias = vue.ref([categoria])
  const peticiones = []
  const avisos = []
  const desplazamientos = []
  let condiciones = { prestable: false, motivo_rechazo: 'Solo uso en sala', motivo_rechazo_en_sala: null, fecha_devolucion: null }
  const api = {
    get: async (ruta) => {
      if (ruta.startsWith('/ejemplares/')) throw new Error('No es un libro')
      if (ruta.startsWith('/equipos/')) return { data: equipo }
      if (ruta === '/prestamos/condiciones') return { data: condiciones }
      if (ruta === '/prestamos/en-curso') return { data: { data: [], total: 0 } }
      return { data: [] }
    },
    post: async (ruta, datos) => { peticiones.push({ ruta, datos }); return { data: {} } },
  }
  const toast = { error: (s) => avisos.push(s), info: (s) => avisos.push(s), success: (s) => avisos.push(s) }
  const modulos = {
    vue: { ...vue, onMounted: () => {} },
    axios: { isAxiosError: () => false },
    'vue-router': { useRoute: () => ({ query: {} }) },
    '@/services/api': { __esModule: true, default: api },
    '@/composables/useToast': { useToast: () => toast, mensajeDeError: (_, texto) => texto },
    '@/composables/useRefrescoPeriodico': { useRefrescoPeriodico: () => {} },
    '@/composables/useModoDocumento': { useModoDocumento: () => ({ modo: vue.ref('rut'), escribir: () => {}, error: () => '' }) },
    '@/stores/auth': { useAuthStore: () => ({ puede: () => true }) },
    '@/utils/documento': { formatearSegunModo: (v) => v, modoDe: () => 'rut' },
    '@/utils/recursos': { useCategoriasRecurso: () => ({ categorias, cargar: async () => {} }), COLORES_RECURSO: {}, ICONOS_RECURSO: {} },
  }
  const modulo = { exports: {} }
  vm.runInNewContext(codigo, {
    module: modulo, exports: modulo.exports, require: (nombre) => modulos[nombre] ?? {},
    console, setTimeout, clearTimeout,
    window: { scrollTo: () => {} },
    document: { getElementById: (id) => ({ scrollIntoView: () => desplazamientos.push(id), focus: () => {} }) },
  })
  const f = modulo.exports.default.setup({}, { expose: () => {} })
  f.usuario.value = { id: 1, multas_pendientes: { cantidad: 0 } }
  f.recursosLibres.value = [equipo]
  return { f, equipo, categoria, peticiones, avisos, desplazamientos, condiciones: (valor) => { condiciones = valor } }
}

test('escanear un PC cambia de pestaña, baja al recurso y abre confirmación sin crear el préstamo', async () => {
  const { f, equipo, peticiones, desplazamientos } = formulario()
  f.buscarLibroPorCodigoPrestamo(equipo.codigo_barras)
  await new Promise((resolver) => setTimeout(resolver, 300))
  assert.equal(f.activeTab.value, 'recursos')
  assert.deepEqual(desplazamientos, ['recurso-notebook'])
  assert.equal(f.prestamoRecursoPendiente.value.codigo, equipo.codigo_barras)
  assert.equal(f.prestamoRecursoPendiente.value.inventario, 'PC de prueba')
  assert.equal(peticiones.length, 0)
})

test('un PC escaneado con código corto abre la confirmación con su nombre y código original', async () => {
  const { f, equipo, peticiones } = formulario()
  f.buscarLibroPorCodigoPrestamo('03222737')
  await new Promise((resolver) => setTimeout(resolver, 300))
  assert.equal(f.prestamoRecursoPendiente.value.codigo, equipo.codigo_barras)
  assert.equal(f.prestamoRecursoPendiente.value.inventario, 'PC de prueba')
  assert.equal(peticiones.length, 0)
})

test('el recuadro del recurso reconoce el código corto sin perder su nombre en la confirmación', () => {
  const { f, equipo, categoria, peticiones } = formulario()
  f.codigos.notebook = '03222737'
  f.pedirPrestamoRecurso(categoria)
  assert.equal(f.prestamoRecursoPendiente.value.codigo, equipo.codigo_barras)
  assert.equal(f.prestamoRecursoPendiente.value.inventario, 'PC de prueba')
  assert.equal(peticiones.length, 0)
})

for (const estado of ['prestado', 'de_baja', 'multas', 'limite']) {
  test(`el escaneo respeta el bloqueo: ${estado}`, async () => {
    const { f, equipo, categoria, peticiones } = formulario()
    if (estado === 'prestado') equipo.disponible = false
    if (estado === 'de_baja') equipo.activo = false
    if (estado === 'multas') f.usuario.value.multas_pendientes.cantidad = 1
    if (estado === 'limite') {
      categoria.limite_por_persona = 1
      f.prestamos.value = [{ tipo_item: 'notebook', estado: 'activo' }]
    }
    await f.pasarARecursos(equipo)
    assert.equal(f.prestamoRecursoPendiente.value, null)
    assert.equal(peticiones.length, 0)
  })
}

test('el material exclusivo de sala cambia automáticamente y después del préstamo vuelve a Para llevar', async () => {
  const { f, peticiones } = formulario()
  f.codigoBarrasPrestamo.value = '30000003186763'
  f.libroEncontradoPrestamo.value = 'Juego de prueba'
  await f.cargarCondiciones()
  assert.equal(f.modoPrestamo.value, 'sala')
  assert.match(f.avisoCambioASala.value, /Cambiado a uso en sala/)
  assert.equal(peticiones.length, 0)
  f.pedirConfirmacionPrestamo()
  assert.equal(f.prestamoPendiente.value.enSala, true)
  await f.confirmarPrestamo()
  assert.equal(peticiones.length, 1)
  assert.equal(peticiones[0].datos.en_sala, true)
  assert.equal(f.modoPrestamo.value, 'domicilio')
  assert.equal(f.avisoCambioASala.value, '')
})

test('alcanzar el máximo a domicilio no se interpreta como material exclusivo de sala', async () => {
  const { f, condiciones } = formulario()
  condiciones({ prestable: true, motivo_rechazo: 'Máximo alcanzado', motivo_rechazo_en_sala: null })
  f.codigoBarrasPrestamo.value = '30000003186763'
  await f.cargarCondiciones()
  assert.equal(f.modoPrestamo.value, 'domicilio')
  assert.equal(f.avisoCambioASala.value, '')
})
