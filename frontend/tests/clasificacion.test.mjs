import assert from 'node:assert/strict'
import test from 'node:test'
import { separarClasificacion, unirClasificacion } from '../src/utils/clasificacion.ts'

for (const texto of ['813.54 G216n 2020', '001.42 S116c 1998', 'T A473p 2013', 'JE1 V986j 2020', '813.54', '813.54 G216n', '813.54 2020', '813.54 G216n 2020 t.1 c.2']) {
  test(`conserva todas las partes de ${texto}`, () => {
    const partes = separarClasificacion(texto)
    assert.ok(partes)
    assert.equal(unirClasificacion(partes), texto)
  })
}
test('separa la clase, el Cutter y el año sin cambiar ceros ni mayúsculas', () => {
  assert.deepEqual(separarClasificacion('001.42 S116c 1998'), { clase: '001.42', cutter: 'S116c', anio: '1998', complemento: '' })
})
test('admite pegar espacios y saltos de línea entre partes', () => {
  assert.deepEqual(separarClasificacion(' 813.54\n G216n  2020 '), { clase: '813.54', cutter: 'G216n', anio: '2020', complemento: '' })
})
test('el contenido local no reconocido permanece en el editor completo', () => {
  assert.equal(separarClasificacion('Revista Chilena de Historia'), null)
  assert.equal(separarClasificacion('Colección especial sin clase'), null)
})
test('se puede borrar o corregir una sección manteniendo las demás', () => {
  const partes = separarClasificacion('813.54 G216n 2020')
  partes.cutter = ''
  assert.equal(unirClasificacion(partes), '813.54 2020')
  partes.cutter = 'M123a'
  assert.equal(unirClasificacion(partes), '813.54 M123a 2020')
})
test('mantiene complementos y datos que no reconoce como Cutter o año', () => {
  const texto = '813.54 G 2020 [edición especial]'
  assert.equal(unirClasificacion(separarClasificacion(texto)), texto)
})
test('el campo vacío se puede completar por secciones', () => {
  assert.deepEqual(separarClasificacion(''), { clase: '', cutter: '', anio: '', complemento: '' })
})
