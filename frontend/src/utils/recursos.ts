import { ref } from 'vue'
import api from '@/services/api'
import type { CategoriaRecurso } from '@/types'

/**
 * Recursos de apoyo: todo lo que se presta y no es un libro (notebooks, audífonos,
 * bicicletas…). Las categorías se administran en Administración → Catálogos; acá están
 * los íconos y colores que se pueden elegir (los mismos que acepta el backend en
 * App\Models\CategoriaRecurso) y el catálogo compartido entre pantallas.
 */

export const ICONOS_RECURSO: Record<string, { nombre: string; trazo: string }> = {
  audifonos: { nombre: 'Audífonos', trazo: 'M3 18v-6a9 9 0 0118 0v6M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3v5zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3v5z' },
  notebook: { nombre: 'Notebook', trazo: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25' },
  cargador: { nombre: 'Cargador', trazo: 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z' },
  bicicleta: { nombre: 'Bicicleta', trazo: 'M5.5 19a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm13 0a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM5.5 15.5L9 8.5h6.5l3 7M9 8.5l3.25 7H5.5M15.5 8.5l-1-3h2.25M7.5 6h3' },
  tablet: { nombre: 'Tablet', trazo: 'M10.5 19.5h3m-6.75 2.25h10.5a2.25 2.25 0 002.25-2.25v-15a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 4.5v15a2.25 2.25 0 002.25 2.25z' },
  calculadora: { nombre: 'Calculadora', trazo: 'M8.25 6h7.5v2.25h-7.5V6zM8.25 12h.008v.008H8.25V12zm0 3h.008v.008H8.25V15zm0 3h.008v.008H8.25V18zm3.75-6h.008v.008H12V12zm0 3h.008v.008H12V15zm0 3h.008v.008H12V18zm3.75-6h.008v.008h-.008V12zm0 3h.008V18h-.008v-3zM6.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-15a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 4.5v15a2.25 2.25 0 002.25 2.25z' },
  camara: { nombre: 'Cámara', trazo: 'M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316zM16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z' },
  llave: { nombre: 'Llave / casillero', trazo: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z' },
  paraguas: { nombre: 'Paraguas', trazo: 'M12 3a9 9 0 00-9 9h18a9 9 0 00-9-9zm0 9v6.75a2.25 2.25 0 01-4.5 0M12 3V2' },
  pelota: { nombre: 'Implemento deportivo', trazo: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-18c2.5 2.5 3.75 5.5 3.75 9S14.5 18.5 12 21m0-18c-2.5 2.5-3.75 5.5-3.75 9S9.5 18.5 12 21M3 12h18' },
  caja: { nombre: 'Otro (caja)', trazo: 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9' },
}

/** Clases escritas completas para que Tailwind las incluya. */
export const COLORES_RECURSO: Record<string, { nombre: string; fondo: string; punto: string }> = {
  violet: { nombre: 'Violeta', fondo: 'bg-violet-50 text-violet-700', punto: 'bg-violet-500' },
  sky: { nombre: 'Celeste', fondo: 'bg-sky-50 text-sky-700', punto: 'bg-sky-600' },
  teal: { nombre: 'Turquesa', fondo: 'bg-teal-50 text-teal-700', punto: 'bg-teal-500' },
  emerald: { nombre: 'Verde', fondo: 'bg-emerald-50 text-emerald-700', punto: 'bg-emerald-600' },
  amber: { nombre: 'Ámbar', fondo: 'bg-amber-50 text-amber-800', punto: 'bg-amber-500' },
  rose: { nombre: 'Rosado', fondo: 'bg-rose-50 text-rose-700', punto: 'bg-rose-500' },
  indigo: { nombre: 'Índigo', fondo: 'bg-indigo-50 text-indigo-700', punto: 'bg-indigo-500' },
  orange: { nombre: 'Naranjo', fondo: 'bg-orange-50 text-orange-700', punto: 'bg-orange-500' },
  slate: { nombre: 'Gris', fondo: 'bg-slate-100 text-slate-700', punto: 'bg-slate-500' },
}

const LIBRO = {
  nombre: 'Libro',
  fondo: 'bg-amber-50 text-dorado-600',
  icono: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
}

// Catálogo compartido: se pide una vez y lo usan Préstamos, el listado, Recursos de apoyo y Reportes.
const categorias = ref<CategoriaRecurso[]>([])
let pedido: Promise<void> | null = null

export function useCategoriasRecurso() {
  /** Con `forzar`, vuelve a pedirlo (después de crear o editar una categoría). */
  function cargar(forzar = false): Promise<void> {
    if (!pedido || forzar) {
      pedido = api
        .get<CategoriaRecurso[]>('/categorias-recurso')
        .then(({ data }) => {
          categorias.value = data
        })
        .catch(() => {
          pedido = null
        })
    }
    return pedido
  }

  return { categorias, cargar }
}

export function categoriaDe(clave: string): CategoriaRecurso | undefined {
  return categorias.value.find((c) => c.clave === clave)
}

/** Nombre, colores e ícono de lo que se prestó: un libro o un recurso de apoyo de cualquier categoría. */
export function itemPrestamo(tipoItem: string): { nombre: string; fondo: string; icono: string } {
  if (tipoItem === 'libro' || !tipoItem) return LIBRO
  const categoria = categoriaDe(tipoItem)
  return {
    nombre: categoria?.nombre ?? 'Recurso de apoyo',
    fondo: COLORES_RECURSO[categoria?.color ?? 'slate']?.fondo ?? COLORES_RECURSO.slate.fondo,
    icono: ICONOS_RECURSO[categoria?.icono ?? 'caja']?.trazo ?? ICONOS_RECURSO.caja.trazo,
  }
}
