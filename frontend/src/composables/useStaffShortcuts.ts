export type StaffShortcutColor = 'emerald' | 'indigo' | 'amber' | 'violet'

export type StaffShortcut = {
  key: 'F1' | 'F2' | 'F3' | 'F4'
  label: string
  name: string
  icon: string
  color: StaffShortcutColor
}

/** Atajos de teclado F1–F4 para navegar entre los módulos principales del panel de staff.
 *  Los colores replican los que ya usa el tab de Reportes (préstamos=indigo, ingresos=emerald, logias=amber). */
export const STAFF_SHORTCUTS: StaffShortcut[] = [
  { key: 'F1', label: 'Registrar Entrada', name: 'entrada', icon: 'M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14', color: 'emerald' },
  { key: 'F2', label: 'Préstamos', name: 'prestamo', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', color: 'indigo' },
  { key: 'F3', label: 'Reservar Logia', name: 'salas', icon: 'M4 6h16M4 12h16M4 18h7', color: 'amber' },
  { key: 'F4', label: 'Reportes', name: 'reportes', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', color: 'violet' },
]

export const STAFF_SHORTCUT_MAP: Record<string, string> = Object.fromEntries(
  STAFF_SHORTCUTS.map((s) => [s.key, s.name]),
)
