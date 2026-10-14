import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'

export default defineConfig({
  plugins: [
    laravel({
      // app.js = storefront (Splide); admin.js = painel (SortableJS).
      input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin.js'],
      refresh: true,
    }),
  ],
})
