import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: true, // listen on 0.0.0.0 so the dev server is reachable from the host
    port: 5180,
    strictPort: true,
    watch: {
      usePolling: true, // reliable file watching inside Docker on macOS/Windows
    },
  },
})
