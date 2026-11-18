import assert from 'node:assert/strict'
import fs from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import vm from 'node:vm'
import { compileScript, parse } from '@vue/compiler-sfc'
import { transformSync } from 'esbuild'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const archivo = new URL('../src/components/salas/ListaConfirmacionAsistencia.vue', import.meta.url)
const { descriptor } = parse(fs.readFileSync(archivo, 'utf8'))
const script = compileScript(descriptor, {
  id: 'salas-confirmacion', inlineTemplate: true, templateOptions: { ssr: true },
})
const codigo = transformSync(script.content, { loader: 'ts', format: 'cjs' }).code
const modulo = { exports: {} }
vm.runInNewContext(codigo, { module: modulo, exports: modulo.exports, require: createRequire(import.meta.url) })

async function lista(segundosRestantes, vencida = false) {
  return renderToString(createSSRApp(modulo.exports.default, {
    pendientes: [{ sala: { nombre: 'Sala de prueba' },
      tramo: { reserva_id: 1, hora_inicio: '10:00', hora_fin: '12:00', cantidad_personas: 1 },
      segundosRestantes, vencida }],
    liberando: null,
  }))
}

test('Administración muestra Sin vencimiento y permite confirmar, sin cuenta regresiva ni liberación', async () => {
  const html = await lista(null)
  assert.match(html, /Sin vencimiento/)
  assert.match(html, /Confirmar llegada/)
  assert.doesNotMatch(html, /Vencido|NaN|Liberar ahora/)
})

test('las salas con plazo conservan su cuenta regresiva', async () => {
  const html = await lista(120)
  assert.match(html, /2:00/)
  assert.match(html, /Confirmar llegada/)
  assert.doesNotMatch(html, /Sin vencimiento/)
})

test('las salas vencidas conservan la opción de liberar', async () => {
  const html = await lista(-1, true)
  assert.match(html, /Vencido/)
  assert.match(html, /Liberar ahora/)
  assert.doesNotMatch(html, /Confirmar llegada/)
})
