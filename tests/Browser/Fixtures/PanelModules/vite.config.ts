// Builds the test-only modules of the Browser suite's panel tests (tests/Browser/Panel), the way an
// addon's build will (PRD 13.4): ES modules whose imports of the panel's shared modules stay bare,
// so the panel's import map resolves them in the browser to the panel's own React, and so does an
// import of a module an addon may not import. Everything else, React Aria Components included, is
// bundled; the one CommonJS module of React Aria that requires React, the useSyncExternalStore
// shim, is pointed at React's own hook (use-sync-external-store.ts). tests/Support/Browser/
// PanelModules builds it once per change of its sources, into a directory it names with --outDir.

import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';

export default defineConfig({
  root: import.meta.dirname,
  logLevel: 'warn',
  resolve: {
    alias: [
      {
        find: /^use-sync-external-store\/shim(?:\/index\.js)?$/,
        replacement: fileURLToPath(new URL('use-sync-external-store.ts', import.meta.url)),
      },
    ],
  },
  build: {
    emptyOutDir: true,
    minify: false,
    modulePreload: false,
    rollupOptions: {
      input: {
        host: 'host.ts',
        'kit-probe': 'kit-probe.ts',
        'cross-origin': 'cross-origin.ts',
        'addons/acme/counter': 'addons/acme/counter.ts',
        'addons/acme/inertia': 'addons/acme/inertia.ts',
      },
      external: ['react', 'react/jsx-runtime', 'react-dom', 'react-dom/client', '@inertiajs/react'],
      preserveEntrySignatures: 'strict',
      output: {
        format: 'es',
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
      },
    },
  },
});
