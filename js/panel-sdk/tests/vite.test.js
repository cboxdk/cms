// The addon build plugin, @cboxdk/cms-panel/vite: a build leaves the panel's shared modules
// external, so the bundle runs on the panel's React and SDK, and fails on an import an addon may
// not make, naming it and why. Its list of shared modules is the panel's.

import assert from 'node:assert/strict';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { build } from 'vite';
import { afterAll, describe, test } from 'vitest';

import cmsPanelAddon, { isShared, refusal, SDK, SHARED_MODULES } from '../vite.js';
import { ROOT } from './sdk.js';

const WORK = join(ROOT, '.cache', `panel-sdk-vite-test-${String(process.pid)}`);

afterAll(() => {
  rmSync(WORK, { recursive: true, force: true });
});

/**
 * Builds an addon whose entry is the source, and gives its output or the build's error.
 *
 * @param {string} name
 * @param {string} source
 * @returns {Promise<{ code: string } | { error: string }>}
 */
async function buildAddon(name, source) {
  const directory = join(WORK, name);

  mkdirSync(directory, { recursive: true });
  writeFileSync(join(directory, 'entry.js'), source);

  try {
    await build({
      configFile: false,
      root: directory,
      logLevel: 'silent',
      plugins: [cmsPanelAddon()],
      build: {
        outDir: join(directory, 'dist'),
        emptyOutDir: true,
        minify: false,
        lib: { entry: join(directory, 'entry.js'), formats: ['es'], fileName: 'entry' },
      },
    });
  } catch (error) {
    return { error: String(error instanceof Error ? error.message : error) };
  }

  const files = readdirSync(join(directory, 'dist')).filter((file) => file.endsWith('.js'));

  return {
    code: files.map((file) => readFileSync(join(directory, 'dist', file), 'utf8')).join('\n'),
  };
}

describe('the addon build plugin', () => {
  test('leaves React and the SDK to the import map', { timeout: 60_000 }, async () => {
    const result = await buildAddon(
      'shared',
      [
        "import { useState } from 'react';",
        "import { jsx } from 'react/jsx-runtime';",
        "import { usePanelHost } from '@cboxdk/cms-panel/extend';",
        'export default function Badge() { const [count] = useState(1); return jsx("span", { children: usePanelHost().t("a.b", { count }) }); }',
        '',
      ].join('\n'),
    );

    assert.ok('code' in result, 'error' in result ? result.error : '');
    assert.match(result.code, /from "react"/);
    assert.match(result.code, /from "react\/jsx-runtime"/);
    assert.match(result.code, /from "@cboxdk\/cms-panel\/extend"/);
    assert.doesNotMatch(result.code, /useSyncExternalStore|__SECRET_INTERNALS|react\.production/);
  });

  test.each([
    [
      "import { router } from '@inertiajs/react'; export default router;",
      '@inertiajs/react',
      /Inertia is the panel's own/,
    ],
    [
      "import { Button } from 'react-aria-components'; export default Button;",
      'react-aria-components',
      /React Aria is internal/,
    ],
    [
      "import { Button } from '@cboxdk/cms-ui-kit'; export default Button;",
      '@cboxdk/cms-ui-kit',
      /The component kit is private/,
    ],
    [
      "import x from 'https://cdn.example.test/x.js'; export default x;",
      'https://cdn.example.test/x.js',
      /content security policy/,
    ],
  ])('fails on %s', { timeout: 60_000 }, async (source, specifier, reason) => {
    const result = await buildAddon(`refused-${specifier.replace(/[^a-z]/gi, '')}`, `${source}\n`);

    assert.ok('error' in result, 'the build passed');
    assert.ok(
      result.error.includes(
        `imports ${specifier}, which an addon of the Cbox CMS panel may not import`,
      ),
      result.error,
    );
    assert.match(result.error, reason);
  });

  test('shares the modules the panel shares, and the SDK with every subpath', () => {
    /** @type {unknown} */
    const parsed = JSON.parse(readFileSync(join(ROOT, 'js/panel/shared-modules.json'), 'utf8'));
    const modules = /** @type {{ shared: Record<string, string> }} */ (parsed);
    const php = readFileSync(
      join(ROOT, 'packages/core/src/Registry/Domain/SharedExternals.php'),
      'utf8',
    );

    assert.deepEqual([...SHARED_MODULES].sort(), Object.keys(modules.shared).sort());
    assert.equal(/const string SDK = '([^']+)';/.exec(php)?.[1], SDK);
    assert.ok(isShared('@cboxdk/cms-panel/extend') && isShared('react-dom/client'));
    assert.ok(!isShared('@cboxdk/cms-panel-app') && !isShared('lodash'));
    assert.equal(refusal('lodash'), null);
    assert.equal(refusal('./Badge'), null);
    assert.notEqual(refusal('//cdn.example.test/x.js'), null);
    assert.notEqual(refusal('@react-aria/focus'), null);
  });
});
