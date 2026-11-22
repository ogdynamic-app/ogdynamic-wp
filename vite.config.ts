import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    host: "127.0.0.1",
    port: 5173,
    strictPort: true,
    cors: true,
    // Dev asset URLs must be absolute: the admin page lives on the WP
    // host while modules load from this server, so root-relative
    // (/src/...) icon URLs would resolve against the WP host and 404.
    // Keep in sync with OGDYNAMIC_DEV_SERVER when customized.
    origin: process.env.VITE_DEV_ORIGIN ?? "http://127.0.0.1:5173",
  },
  base: "./",
  build: {
    outDir: "dist/admin",
    emptyOutDir: true,
    manifest: true,
    // Never inline imported assets as data URIs — icons and images load
    // from real files (e.g. assets/icons/*-mark.*) so markup stays clean.
    assetsInlineLimit: 0,
    rolldownOptions: {
      input: {
        admin: "src/admin/main.ts",
      },
      output: {
        entryFileNames: "assets/[name].js",
        chunkFileNames: "assets/[name]-[hash].js",
        assetFileNames: "assets/[name][extname]",
      },
    },
  },
});
