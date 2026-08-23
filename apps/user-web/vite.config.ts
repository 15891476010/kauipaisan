import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/dev': {
        target: 'http://kuaipaisanapi.tzgpt.top:5095',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/dev/, '/api/v1'),
      },
    },
  },
})
