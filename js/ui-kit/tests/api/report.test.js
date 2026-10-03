// The API report of the component kit: js/ui-kit/api/cms-ui-kit.api.md is what the kit exports
// now, every export carries exactly one stability tag, and the comparison fails on a report that
// differs. It is part of the JS unit suite (`npm run test:js`), not of `npm run test:kit`, because it
// writes TypeScript's declarations of the kit, which takes a few seconds; `npm run api:report`
// writes the report anew.

import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { afterAll, describe, test } from 'vitest';

import { apiReport, REPORT, stabilities, stamp } from '../../scripts/api-report.js';
import { ROOT } from '../kit.js';

/** A work directory of this test's own, so a run beside `npm run api:report` never meets it. */
const WORK = join(ROOT, '.cache', `ui-kit-api-test-${String(process.pid)}`);

afterAll(() => {
  rmSync(WORK, { recursive: true, force: true });
});

describe('the API report', () => {
  test('is what the kit exports now', { timeout: 120_000 }, () => {
    const report = apiReport(ROOT, { work: join(WORK, 'kit') });

    assert.deepEqual(report.problems, []);
    assert.equal(
      report.changed,
      false,
      `${REPORT} is not what the kit exports; run npm run api:report and review the diff`,
    );
  });

  test(
    'fails when the committed report differs from what the kit exports',
    { timeout: 120_000 },
    () => {
      const committed = readFileSync(join(ROOT, REPORT), 'utf8');
      const planted = join(WORK, 'planted.api.md');

      mkdirSync(WORK, { recursive: true });
      writeFileSync(
        planted,
        committed.replace(/\n\/\/ @experimental\nexport function Badge[^\n]*/, ''),
      );

      assert.notEqual(readFileSync(planted, 'utf8'), committed);
      assert.equal(apiReport(ROOT, { report: planted, work: join(WORK, 'planted') }).changed, true);
    },
  );

  test('names every export with its stability tag, never with API Extractor’s @public', () => {
    const committed = readFileSync(join(ROOT, REPORT), 'utf8');

    assert.doesNotMatch(committed, /^\/\/ @public/m);
    assert.match(committed, /^\/\/ @experimental\nexport function Badge\(/m);
    assert.match(committed, /^\/\/ @stable\nexport function KitI18nProvider\(/m);
  });
});

describe('the stability tags', () => {
  test('are on every export of the kit', { timeout: 60_000 }, () => {
    assert.deepEqual(stabilities(ROOT).problems, []);
  });

  test('are required, exactly one per export', { timeout: 60_000 }, () => {
    const entry = join(WORK, 'entry.ts');

    mkdirSync(WORK, { recursive: true });
    writeFileSync(
      entry,
      [
        '/** @experimental */',
        'export const tagged = 1;',
        '/** A value without a tag. */',
        'export const untagged = 2;',
        '/**',
        ' * @stable',
        ' * @experimental',
        ' */',
        'export const twice = 3;',
        '',
      ].join('\n'),
    );
    const found = stabilities(ROOT, entry);

    assert.equal(found.tags.get('tagged'), '@experimental');
    assert.deepEqual(
      found.problems.map((problem) => problem.replace(/^[^ ]+ /, '')),
      [
        'untagged carries no stability tag; give it exactly one of @stable and @experimental',
        'twice carries @stable and @experimental; give it exactly one of @stable and @experimental',
      ],
    );
  });

  test('replace @public in the report', () => {
    const report = [
      '// @public',
      'export function A(): void;',
      '',
      '// @public (undocumented)',
      'export interface B {',
      '}',
    ].join('\n');

    assert.equal(
      stamp(
        report,
        new Map([
          ['A', '@stable'],
          ['B', '@experimental'],
        ]),
      ),
      [
        '// @stable',
        'export function A(): void;',
        '',
        '// @experimental (undocumented)',
        'export interface B {',
        '}',
      ].join('\n'),
    );
  });
});
