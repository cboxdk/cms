// Builds the panel into dist/ with a manifest, so the server can name the hashed assets of the
// entry. The panel ships prebuilt, so an application's deploy needs no Node (PRD 13.4).

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  root: import.meta.dirname,
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'src/app.tsx',
    },
  },
});
