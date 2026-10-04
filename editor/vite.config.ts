import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds ../assets/editor/editor.js + editor.css for wp_enqueue_script().
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../assets/editor',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'editor.js',
        assetFileNames: (info) => (info.name?.endsWith('.css') ? 'editor.css' : '[name][extname]'),
      },
    },
  },
});
