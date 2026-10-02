// Builds the panel with a manifest into packages/panel/dist, which git ignores, so the panel module
// can name the hashed assets of the entry and serve them below its own route; PRD 13.4 has the
// panel ship prebuilt, so an application's deploy needs no Node. The base is relative, so the
// chunks find each other below whatever prefix an application mounts the panel at.
// `composer panel:build` runs this build in the dev image.

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  root: import.meta.dirname,
  base: './',
  plugins: [react()],
  build: {
    outDir: '../../packages/panel/dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'src/app.tsx',
    },
  },
});
