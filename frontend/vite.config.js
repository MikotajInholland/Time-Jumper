import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const readable = mode === 'readable'

  return {
    plugins: [vue()],
    server: {
      host: '0.0.0.0',
      port: 5173,
      watch: {
        usePolling: true,
      },
    },
    define: {
      'import.meta.env.VITE_API_URL': JSON.stringify(process.env.VITE_API_URL || 'http://localhost/api'),
    },
    build: {
      // Default build: minified for smaller downloads. Use `npm run build:readable` for
      // multi-line, unminified output in dist/ (easier to inspect; still generated — edit src/).
      minify: readable ? false : 'esbuild',
      cssMinify: !readable,
    },
  }
})
