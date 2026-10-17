import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Fuera de Docker, solo este computador. El contenedor escucha en todas sus
    // interfaces (VITE_HOST en frontend/Dockerfile) y docker-compose.yml decide
    // hasta dónde se publica.
    host: process.env.VITE_HOST || 'localhost',
    port: 5173,
    watch: {
      usePolling: true,
    },
  },
})
