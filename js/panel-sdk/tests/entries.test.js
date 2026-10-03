// The SDK's re-exports of the component kit (npm run generate:sdk): /ui holds the kit's @stable
// exports and /experimental its @experimental ones, values and types apart, and the committed
// modules are what the kit's tags give now, which gate 6 checks too.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import * as prettier from 'prettier';
import { describe, test } from 'vitest';

import { ENTRIES, entryModule, kitExports } from '../scripts/generate-entries.js';
import { ROOT } from './sdk.js';

describe('the re-exports of the kit', () => {
  test('are what the kit exports now, by stability tag', { timeout: 60_000 }, async () => {
    const { exports, problems } = kitExports(ROOT);

    assert.deepEqual(problems, []);

    for (const [tag, path] of /** @type {['@stable' | '@experimental', string][]} */ (
      Object.entries(ENTRIES)
    )) {
      const options = (await prettier.resolveConfig(join(ROOT, path))) ?? {};
      const made = await prettier.format(entryModule(exports, tag), {
        ...options,
        filepath: join(ROOT, path),
      });

      assert.equal(
        readFileSync(join(ROOT, path), 'utf8'),
        made,
        `${path} is not what npm run generate:sdk writes`,
      );
    }

    assert.ok(
      exports.some(
        (exported) =>
          exported.name === 'Button' && exported.tag === '@experimental' && exported.value,
      ),
    );
    assert.ok(
      exports.some(
        (exported) =>
          exported.name === 'KitLocale' && exported.tag === '@stable' && !exported.value,
      ),
    );
  });

  test('put values and types of each stability apart', () => {
    const module = entryModule(
      [
        { name: 'Card', tag: '@experimental', value: true },
        { name: 'CardProps', tag: '@experimental', value: false },
        { name: 'KitI18nProvider', tag: '@stable', value: true },
      ],
      '@experimental',
    );

    assert.match(module, /^export \{ Card \} from '@cboxdk\/cms-ui-kit';$/m);
    assert.match(module, /^export type \{ CardProps \} from '@cboxdk\/cms-ui-kit';$/m);
    assert.doesNotMatch(module, /KitI18nProvider/);
    assert.match(entryModule([], '@stable'), /^export \{\};$/m);
  });
});
