/**
 * Colores de las secciones del formulario de catalogación: la cabecera, el número,
 * las etiquetas MARC, las fichas (chips) de autores o materias y el paso del índice.
 */
export type TonoFicha = 'indigo' | 'dorado' | 'teal' | 'pizarra' | 'violeta'

export const TONOS_FICHA: Record<TonoFicha, { cabecera: string; numero: string; detalle: string; etiqueta: string; chip: string; paso: string }> = {
  indigo: {
    cabecera: 'bg-indigo-50/80',
    numero: 'bg-indigo-600 text-white',
    detalle: 'text-indigo-700/80',
    etiqueta: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    chip: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    paso: 'bg-indigo-100 text-indigo-700',
  },
  dorado: {
    cabecera: 'bg-amber-50/80',
    numero: 'bg-[#b08d57] text-white',
    detalle: 'text-dorado-600',
    etiqueta: 'bg-amber-50 text-dorado-600 ring-amber-100',
    chip: 'bg-amber-50 text-dorado-700 ring-amber-200',
    paso: 'bg-amber-100 text-dorado-600',
  },
  teal: {
    cabecera: 'bg-teal-50/80',
    numero: 'bg-teal-600 text-white',
    detalle: 'text-teal-700/80',
    etiqueta: 'bg-teal-50 text-teal-700 ring-teal-100',
    chip: 'bg-teal-50 text-teal-800 ring-teal-200',
    paso: 'bg-teal-100 text-teal-700',
  },
  pizarra: {
    cabecera: 'bg-slate-100/80',
    numero: 'bg-[#3F5F8A] text-white',
    detalle: 'text-pizarra-600',
    etiqueta: 'bg-slate-100 text-pizarra-600 ring-slate-200',
    chip: 'bg-slate-100 text-pizarra-700 ring-slate-200',
    paso: 'bg-slate-200 text-pizarra-600',
  },
  violeta: {
    cabecera: 'bg-violet-50/80',
    numero: 'bg-violet-600 text-white',
    detalle: 'text-violet-700/80',
    etiqueta: 'bg-violet-50 text-violet-700 ring-violet-100',
    chip: 'bg-violet-50 text-violet-800 ring-violet-200',
    paso: 'bg-violet-100 text-violet-700',
  },
}
