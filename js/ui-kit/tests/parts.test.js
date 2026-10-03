// The curated part hooks (data-cms-part): the kit renders exactly the parts tokens.json lists,
// each on one element with its name written as a literal, so the list in the catalogue, the
// TypeScript PartName, the theme schema and docs/ui/tokens.md say what the markup has.
// `npm run test:kit -- parts` runs this file.

import assert from 'node:assert/strict';
import { describe, test } from 'vitest';

import { catalogue, kitFiles, read } from './kit.js';

const ATTRIBUTE = /data-cms-part(\s*=\s*(\{?\s*["'][^"']*["']\s*\}?|\{[^}]*\}))?/g;

describe('the part hooks', () => {
  const { catalogue: tokens } = catalogue();
  /** @type {Map<string, string[]>} */
  const rendered = new Map();
  /** @type {string[]} */
  const dynamic = [];

  for (const path of kitFiles('.tsx')) {
    for (const match of read(path).matchAll(ATTRIBUTE)) {
      const literal = /["']([^"']*)["']/.exec(match[2] ?? '');

      if (literal === null || /^\{[^"']*\}$/.test(match[2] ?? '')) {
        dynamic.push(`${path}: ${match[0]}`);
      } else {
        const name = literal[1] ?? '';
        rendered.set(name, [...(rendered.get(name) ?? []), path]);
      }
    }
  }

  test('are written as literals', () => {
    assert.deepEqual(dynamic, []);
  });

  test('are exactly the parts of tokens.json', () => {
    assert.deepEqual(
      [...rendered.keys()].sort(),
      tokens.parts.map((part) => part.name),
    );
  });

  test('each sit on one element of the kit', () => {
    for (const [name, paths] of rendered) {
      assert.equal(paths.length, 1, `the part ${name} is rendered in ${paths.join(', ')}`);
    }
  });
});
