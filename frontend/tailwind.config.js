/** @type {import('tailwindcss').Config} */
// La interfaz usa la paleta estándar de Tailwind (indigo/slate/gray para la base,
// emerald/amber/red para estados) más el degradado morado institucional y el
// dorado de la pestaña activa, escritos directamente en los componentes.
// Guía completa: docs/estilo-visual.md
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      borderRadius: {
        DEFAULT: '0.625rem',
      },
      fontFamily: {
        sans: ['"Source Sans 3"', 'system-ui', 'sans-serif'],
        serif: ['"Source Serif 4"', 'Georgia', 'serif'],
      },
    },
  },
  plugins: [],
}
