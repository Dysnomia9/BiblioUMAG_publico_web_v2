

export function calcularDV(rut: number): string {
  let suma = 0
  let multiplicador = 2
  const rutStr = rut.toString()
  for (let i = rutStr.length - 1; i >= 0; i--) {
    suma += parseInt(rutStr[i]) * multiplicador
    multiplicador = multiplicador === 7 ? 2 : multiplicador + 1
  }
  const resto = 11 - (suma % 11)
  if (resto === 11) return '0'
  if (resto === 10) return 'K'
  return resto.toString()
}

export function validarRut(rut: string): boolean {
  if (!rut) return false
  const cleaned = rut.replace(/\./g, '').replace('-', '')
  if (cleaned.length < 2) return false
  const body = cleaned.slice(0, -1)
  const dv = cleaned.slice(-1).toUpperCase()
  const num = parseInt(body, 10)
  if (isNaN(num)) return false
  return calcularDV(num) === dv
}

export function formatRut(value: string): string {
  let cleaned = value.replace(/[^0-9kK]/g, '')
  if (cleaned.length === 0) return ''
  let dv = ''
  if (cleaned.length > 1) {
    dv = cleaned.slice(-1).toUpperCase()
    cleaned = cleaned.slice(0, -1)
  }
  const formatted = cleaned.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return dv ? `${formatted}-${dv}` : formatted
}

export function cleanRut(rut: string): string {
  return rut.replace(/\./g, '').replace('-', '').toUpperCase()
}

export function useRut() {
  return { calcularDV, validarRut, formatRut, cleanRut }
}
