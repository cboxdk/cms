// The kit's own texts (GUARDRAILS 8): its catalogues in js/ui-kit/src/i18n/catalogues have the
// same keys in Danish and English, every text is filled in, and every key is used by the kit.
// `npm run test:kit -- i18n` runs this file.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, test } from 'vitest';

import { translationParityProblems } from '../../tooling/translation-parity.js';
import { CATALOGUES_DIRECTORY, kitFiles, read } from './kit.js';

describe("the kit's catalogues", () => {
  test('pass the translation parity check in da and en', () => {
    assert.deepEqual(translationParityProblems(CATALOGUES_DIRECTORY, ['da', 'en']), []);
  });

  test('have only keys the kit uses, under kit.', () => {
    /** @type {unknown} */
    const english = JSON.parse(readFileSync(join(CATALOGUES_DIRECTORY, 'en.json'), 'utf8'));
    const keys = Object.keys(/** @type {Record<string, string>} */ (english));
    const source = kitFiles('.tsx')
      .map((path) => read(path))
      .join('\n');

    assert.ok(keys.length > 0);

    for (const key of keys) {
      assert.match(key, /^kit\./);
      assert.ok(source.includes(`'${key}'`), `the key ${key} is used by no component of the kit`);
    }
  });
});
