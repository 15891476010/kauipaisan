import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  base: '/h5/',
  plugins: [react()],
  server: {
    proxy: {
      '/prod_api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/prod_api/, '/api'),
      },
    },
  },
})
