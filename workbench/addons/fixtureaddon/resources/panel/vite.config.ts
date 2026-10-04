// Builds the fixture addon's panel bundle into dist/panel, as an addon's own build does (PRD 13.4):
// the SDK's plugin leaves the panel's shared modules external, checks the built files and writes
// panel-manifest.json, and signs it into panel-signature.json with the addon's test key, which
// stands in for a publisher's key kept outside the repository. Run it from the repository's root
// with `npm run build:fixture-addon`, and commit dist/panel.

import { readFileSync } from 'node:fs';

import cmsPanelAddon from '@cboxdk/cms-panel/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  root: import.meta.dirname,
  logLevel: 'warn',
  plugins: [
    cmsPanelAddon({
      namespace: 'fixtureaddon',
      contributions: [],
      sign: {
        privateKey: readFileSync(
          new URL('../../panel-signing-test-key.pem', import.meta.url),
          'utf8',
        ),
      },
    }),
  ],
  build: {
    outDir: '../../dist/panel',
    emptyOutDir: true,
    minify: false,
    lib: { entry: 'src/panel.ts', formats: ['es'], fileName: 'panel' },
  },
});
