// The API report of the SDK: js/panel-sdk/api/cms-panel.api.md is what each subpath of
// @cboxdk/cms-panel exports now, so a test fails on a change to the API that is not recorded by
// writing the report anew (`npm run api:report`); and every export of every subpath carries the
// stability of its subpath, @experimental in /experimental and @stable everywhere else.

import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { afterAll, describe, test } from 'vitest';

import { apiReport, REPORT, stabilities, stamp, SUBPATHS } from '../../scripts/api-report.js';
import { ROOT } from '../sdk.js';

/** A work directory of this test's own, so a run beside `npm run api:report` never meets it. */
const WORK = join(ROOT, '.cache', `panel-sdk-api-test-${String(process.pid)}`);

afterAll(() => {
  rmSync(WORK, { recursive: true, force: true });
});

describe('the API report of the SDK', () => {
  test('is what the SDK exports now', { timeout: 180_000 }, () => {
    const report = apiReport(ROOT, { work: join(WORK, 'sdk') });

    assert.deepEqual(report.problems, []);
    assert.equal(
      report.changed,
      false,
      `${REPORT} is not what the SDK exports; record the change, run npm run api:report and review the diff`,
    );
  });

  test(
    'fails on a change to the API the committed report does not record',
    { timeout: 180_000 },
    () => {
      const committed = readFileSync(join(ROOT, REPORT), 'utf8');
      const planted = join(WORK, 'planted.api.md');

      mkdirSync(WORK, { recursive: true });
      writeFileSync(
        planted,
        committed.replace(/\n\/\/ @stable\nexport function definePanelAddon[^\n]*/, ''),
      );

      assert.notEqual(readFileSync(planted, 'utf8'), committed);
      assert.equal(apiReport(ROOT, { report: planted, work: join(WORK, 'planted') }).changed, true);
    },
  );

  test('has a section per subpath of package.json, each export with its stability tag', () => {
    const committed = readFileSync(join(ROOT, REPORT), 'utf8');
    /** @type {unknown} */
    const parsed = JSON.parse(readFileSync(join(ROOT, 'js/panel-sdk/package.json'), 'utf8'));
    const manifest = /** @type {{ exports: Record<string, string> }} */ (parsed);

    assert.deepEqual(
      [...committed.matchAll(/^### @cboxdk\/cms-panel(\/\S+)$/gm)].map((match) => match[1]),
      SUBPATHS.map((subpath) => subpath.name),
    );
    assert.deepEqual(
      Object.keys(manifest.exports)
        .filter((subpath) => !subpath.endsWith('.css'))
        .map((subpath) => subpath.slice(1))
        .sort(),
      SUBPATHS.map((subpath) => subpath.name).sort(),
    );
    assert.deepEqual(
      SUBPATHS.map((subpath) => `./${subpath.entry.slice('js/panel-sdk/'.length)}`).sort(),
      Object.entries(manifest.exports)
        .filter(([subpath]) => !subpath.endsWith('.css'))
        .map(([, entry]) => entry)
        .sort(),
    );
    assert.doesNotMatch(committed, /^\/\/ @public/m);
    assert.match(committed, /^\/\/ @stable\nexport function definePanelAddon</m);
    assert.match(committed, /^\/\/ @experimental\nexport type ActionHandler</m);
  });
});

describe('the stability of the subpaths', () => {
  test(
    'holds: /experimental only experimental API, every other subpath only stable API',
    { timeout: 60_000 },
    () => {
      const found = stabilities(ROOT);

      assert.deepEqual(found.problems, []);
      assert.equal(found.tags.get('/extend')?.get('usePanelHost'), '@stable');
      assert.equal(found.tags.get('/experimental')?.get('Button'), '@experimental');
      assert.equal(found.tags.get('/ui')?.get('KitI18nProvider'), '@stable');
    },
  );

  test(
    'refuses an export without a tag and an export of another stability than its subpath',
    { timeout: 60_000 },
    () => {
      mkdirSync(WORK, { recursive: true });
      writeFileSync(
        join(WORK, 'stable.ts'),
        [
          '/** @experimental */',
          'export const early = 1;',
          '/** A value without a tag. */',
          'export const untagged = 2;',
          '',
        ].join('\n'),
      );
      const relative = WORK.slice(ROOT.length + 1);
      const found = stabilities(ROOT, [
        { name: '/planted', entry: `${relative}/stable.ts`, level: '@stable' },
      ]);

      assert.deepEqual(
        found.problems.map((problem) => problem.replace(/^[^ ]+ /, '')),
        [
          '/planted exports early, which is @experimental; @cboxdk/cms-panel/planted exports only @stable API (section 2.1 of the panel extension architecture)',
          '/planted exports untagged, which carries no stability tag; give it exactly one of @stable and @experimental',
        ],
      );
    },
  );

  test('replace @public in the report, also on a default export', () => {
    const report = [
      '// @public',
      'function plugin(): void;',
      'export default plugin;',
      '',
      '// @public (undocumented)',
      'export interface B {',
      '}',
    ].join('\n');

    assert.equal(
      stamp(
        report,
        new Map([
          ['plugin', '@stable'],
          ['B', '@experimental'],
        ]),
      ),
      [
        '// @stable',
        'function plugin(): void;',
        'export default plugin;',
        '',
        '// @experimental (undocumented)',
        'export interface B {',
        '}',
      ].join('\n'),
    );
  });
});
