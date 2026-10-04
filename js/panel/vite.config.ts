// Builds the panel with a manifest into packages/panel/dist, which git ignores, so the panel module
// can name the hashed assets of the entry and serve them below its own route; PRD 13.4 has the
// panel ship prebuilt, so an application's deploy needs no Node. The base is relative, so the
// chunks find each other below whatever prefix an application mounts the panel at.
// `composer panel:build` runs this build in the dev image.
//
// Besides the panel's entry, the build has an entry for each of the panel's shared modules (PRD
// 13.4), so the import map the panel module writes can hand an addon the very React the panel
// runs on and the panel's own copy of the SDK, @cboxdk/cms-panel with its subpaths, and an entry
// for each module an addon may not import, which throws instead. shared-modules.json lists all
// three, by specifier, with the name of each entry, which is what the panel module's ViteManifest
// reads them by.
//
// React 19 ships as CommonJS, and Rolldown gives `export * from 'react'` only a default export,
// so each shared entry names every export of the module itself: the names are read from the
// module in a Node process with NODE_ENV production, the build the panel ships, and the entry
// re-exports them from the same module the panel's own code imports. The panel's code and the
// entry then share one chunk, so there is one React. The SDK's subpaths are ES modules, so their
// entries re-export them as they are. A refused entry calls refuse() before an addon that imports
// it runs, and re-exports every name of the module, so the browser links the addon and reaches
// the throw instead of failing on a missing export.

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vite';

import modules from './shared-modules.json' with { type: 'json' };

const PREFIX = '\0cms-panel-module:';

const refusal = fileURLToPath(new URL('src/externals/refused.ts', import.meta.url));

/** The names a CommonJS module exports in production, read in a Node process of their own. */
function exportNames(specifier: string): string[] {
  const output = execFileSync(
    process.execPath,
    [
      '--input-type=commonjs',
      '-e',
      'process.stdout.write(JSON.stringify(Object.keys(require(process.argv[1]))))',
      specifier,
    ],
    { cwd: import.meta.dirname, env: { ...process.env, NODE_ENV: 'production' } },
  ).toString();
  const names: unknown = JSON.parse(output);

  if (!Array.isArray(names) || !names.every((name): name is string => typeof name === 'string')) {
    throw new Error(`The shared module ${specifier} does not list its exports.`);
  }

  return names.filter((name) => /^[A-Za-z_$][\w$]*$/.test(name) && name !== 'default').sort();
}

function sharedEntry(specifier: string): string {
  const names = exportNames(specifier);

  return [
    `import shared from ${JSON.stringify(specifier)};`,
    'export default shared;',
    `export const { ${names.join(', ')} } = shared;`,
    '',
  ].join('\n');
}

function sdkEntry(specifier: string): string {
  return `export * from ${JSON.stringify(specifier)};\n`;
}

function refusedEntry(specifier: string): string {
  return [
    `import { refuse } from ${JSON.stringify(refusal)};`,
    `refuse(${JSON.stringify(specifier)});`,
    `export * from ${JSON.stringify(specifier)};`,
    '',
  ].join('\n');
}

/** The build's inputs for the shared and refused modules, by the name of their entry. */
function sharedModuleInputs(): Record<string, string> {
  return Object.fromEntries(
    [
      ...Object.entries(modules.shared),
      ...Object.entries(modules.sdk),
      ...Object.entries(modules.refused),
    ].map(([specifier, name]) => [name, `${PREFIX}${specifier}`]),
  );
}

/** Loads the shared and refused entries. */
function sharedModules(): Plugin {
  return {
    name: 'cms-panel-shared-modules',
    resolveId(id) {
      return id.startsWith(PREFIX) ? id : null;
    },
    load(id) {
      if (!id.startsWith(PREFIX)) {
        return null;
      }

      const specifier = id.slice(PREFIX.length);

      if (Object.hasOwn(modules.shared, specifier)) {
        return sharedEntry(specifier);
      }

      if (Object.hasOwn(modules.sdk, specifier)) {
        return sdkEntry(specifier);
      }

      if (Object.hasOwn(modules.refused, specifier)) {
        return refusedEntry(specifier);
      }

      throw new Error(`${specifier} is neither a shared nor a refused module of the panel.`);
    },
  };
}

export default defineConfig({
  root: import.meta.dirname,
  base: './',
  plugins: [react(), sharedModules()],
  build: {
    outDir: '../../packages/panel/dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: { app: 'src/app.tsx', ...sharedModuleInputs() },
      preserveEntrySignatures: 'strict',
    },
  },
});
