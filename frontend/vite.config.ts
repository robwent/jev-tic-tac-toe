import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
  // `pnpm dev` serves the page. PHP comes from whatever serves public/, by
  // default `php -S localhost:8000 -t public`. Override with BACKEND_URL,
  // either in the environment or in frontend/.env.local.
  const backend = loadEnv(mode, '.', '').BACKEND_URL ?? 'http://localhost:8000'

  return {
    plugins: [vue()],
    base: './',
    publicDir: false,
    build: {
      outDir: '../public',
      emptyOutDir: false,
      assetsDir: 'assets',
    },
    server: {
      proxy: {
        '/api': { target: backend, changeOrigin: true, secure: false },
        '/data': { target: backend, changeOrigin: true, secure: false },
      },
    },
  }
})
