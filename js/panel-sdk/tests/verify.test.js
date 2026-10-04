// `cms-panel-addon verify` (decision D7 of the panel extension architecture): the committed bundle
// of an addon verifies when every file has the bytes its manifest names and a fresh build writes
// the same manifest; a file edited by hand after the build fails, naming the file, and so does a
// bundle whose sources changed after it was built. The bin prints the problems and exits 1.

import assert from 'node:assert/strict';
import { appendFileSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { build } from 'vite';
import { afterAll, describe, test } from 'vitest';

import { main, manifestDifferences, parseArguments, verifyBundle } from '../verify.js';
import cmsPanelAddon, { MANIFEST } from '../vite.js';
import { ROOT } from './sdk.js';

const WORK = join(ROOT, '.cache', `panel-sdk-verify-test-${String(process.pid)}`);

afterAll(() => {
  rmSync(WORK, { recursive: true, force: true });
});

/**
 * Writes an addon whose entry registers one contribution, with a stylesheet, in a directory of
 * its own, and gives a build of it into any directory.
 *
 * @param {string} name
 * @returns {{ root: string, build: (outDir: string) => Promise<void> }}
 */
function addon(name) {
  const root = join(WORK, name);

  mkdirSync(root, { recursive: true });
  writeFileSync(
    join(root, 'entry.js'),
    [
      "import { definePanelAddon } from '@cboxdk/cms-panel/extend';",
      "import './styles.css';",
      "export default definePanelAddon({ 'acme.badge': () => import('./badge.js') });",
      '',
    ].join('\n'),
  );
  writeFileSync(join(root, 'badge.js'), 'export default () => null;\n');
  writeFileSync(
    join(root, 'styles.css'),
    '@layer cms.addon.acme {\n  .acme-badge { color: var(--cms-color-text); }\n}\n',
  );

  return {
    root,
    build: async (outDir) => {
      await build({
        configFile: false,
        root,
        logLevel: 'silent',
        plugins: [cmsPanelAddon({ namespace: 'acme', contributions: ['acme.badge'] })],
        build: {
          outDir,
          emptyOutDir: true,
          minify: false,
          lib: { entry: join(root, 'entry.js'), formats: ['es'], fileName: 'entry' },
        },
      });
    },
  };
}

describe('cms-panel-addon verify', () => {
  test(
    'verifies a bundle the build wrote, and fails one edited by hand or built from other sources',
    { timeout: 120_000 },
    async () => {
      const { root, build: buildAddon } = addon('verified');
      const dist = join(root, 'dist/panel');

      await buildAddon(dist);

      assert.deepEqual(await verifyBundle({ root, dist, build: buildAddon }), {
        problems: [],
        files: 3,
      });

      // The entry is edited after the build: its bytes are not what the manifest names.
      appendFileSync(join(dist, 'entry.js'), '/* edited by hand */\n');
      const edited = await verifyBundle({ root, dist, build: buildAddon });

      assert.equal(edited.problems.length, 1);
      assert.match(edited.problems[0] ?? '', /^entry\.js was changed after the build/);

      // The sources change after the build: a fresh build writes another entry.
      await buildAddon(dist);
      writeFileSync(join(root, 'badge.js'), "export default () => 'changed';\n");
      const stale = await verifyBundle({ root, dist, build: buildAddon });

      assert.ok(stale.problems.some((problem) => /is not what a fresh build writes/.test(problem)));
    },
  );

  test('names a missing manifest, and a build that fails', async () => {
    const { root } = addon('unbuilt');

    const missing = await verifyBundle({ root, build: () => Promise.resolve() });
    assert.equal(missing.files, 0);
    assert.match(missing.problems[0] ?? '', new RegExp(`${MANIFEST} is missing`));

    const { root: broken, build: buildBroken } = addon('broken');
    await buildBroken(join(broken, 'dist/panel'));
    const failed = await verifyBundle({
      root: broken,
      build: () => Promise.reject(new Error('Bundling failed.')),
    });
    assert.deepEqual(failed.problems, ['the addon does not build: Bundling failed.']);
  });

  test('compares two manifests file by file', () => {
    const committed = {
      contributions: ['acme.badge'],
      entry: 'entry.js',
      externals: ['@cboxdk/cms-panel/extend'],
      files: [
        { integrity: 'sha384-a', kind: 'script', path: 'entry.js' },
        { integrity: 'sha384-b', kind: 'style', path: 'entry.css' },
      ],
    };

    assert.deepEqual(manifestDifferences(committed, committed), []);
    assert.deepEqual(
      manifestDifferences(committed, {
        ...committed,
        contributions: [],
        files: [
          { integrity: 'sha384-c', kind: 'script', path: 'entry.js' },
          { integrity: 'sha384-d', kind: 'script', path: 'chunk.js' },
        ],
      }),
      [
        'the contributions are ["acme.badge"], and a fresh build writes [].',
        'entry.js is not what a fresh build writes: the sources changed after the build, or the build is not reproducible.',
        'entry.css is committed, and a fresh build does not write it.',
        'chunk.js is written by a fresh build and not committed.',
      ],
    );
  });

  test('reads its arguments, and exits 64 on any other', async () => {
    assert.deepEqual(parseArguments(['verify'], '/addon'), {
      command: 'verify',
      root: '/addon',
      dist: undefined,
    });
    assert.deepEqual(parseArguments(['verify', '--root=sub', '--dist=out'], '/addon'), {
      command: 'verify',
      root: '/addon/sub',
      dist: 'out',
    });
    assert.ok('usage' in parseArguments([], '/addon'));
    assert.ok('usage' in parseArguments(['verify', '--watch'], '/addon'));

    /** @type {string[]} */
    const errors = [];
    const exit = await main(['build'], '/addon', {
      out: () => undefined,
      error: (line) => {
        errors.push(line);
      },
    });

    assert.equal(exit, 64);
    assert.match(errors[0] ?? '', /^Usage: cms-panel-addon verify/);
  });

  test('prints each problem of a bundle that does not verify and exits 1', async () => {
    const { root } = addon('printed');
    /** @type {string[]} */
    const errors = [];

    assert.equal(
      await main(['verify', `--root=${root}`], root, {
        out: () => undefined,
        error: (line) => {
          errors.push(line);
        },
      }),
      1,
    );
    assert.match(errors[0] ?? '', /^The bundle does not verify: .*panel-manifest\.json is missing/);
  });
});
