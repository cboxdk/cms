// The cascade layers of the kit and the panel: js/ui-kit/src/layers.css declares the order
// cms.reset, cms.tokens, cms.addon, cms.kit, cms.panel, cms.theme once; every other stylesheet of
// the kit puts all its rules in its own layer and uses no !important; the panel imports layers.css
// before any other stylesheet; and the kit's stylesheets read only tokens that exist and never a
// primitive one. `npm run test:kit -- layers` runs this file.

import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { LAYERS, PREFIX } from '../scripts/tokens.js';
import { catalogue, kitFiles, read } from './kit.js';

/**
 * The statements at the top level of a stylesheet, without comments: each an at-rule ending in a
 * semicolon, or a prelude with its block, with the text inside the block.
 *
 * @param {string} css
 * @returns {{ prelude: string, block: string | null }[]}
 */
function topLevel(css) {
  const text = css.replace(/\/\*[\s\S]*?\*\//g, '');
  /** @type {{ prelude: string, block: string | null }[]} */
  const statements = [];
  let start = 0;
  let depth = 0;
  let open = 0;

  for (let index = 0; index < text.length; index++) {
    const character = text[index];

    if (character === '{') {
      if (depth === 0) {
        open = index;
      }

      depth++;
    } else if (character === '}') {
      depth--;

      if (depth === 0) {
        statements.push({
          prelude: text.slice(start, open).trim(),
          block: text.slice(open + 1, index),
        });
        start = index + 1;
      }
    } else if (character === ';' && depth === 0) {
      statements.push({ prelude: text.slice(start, index).trim(), block: null });
      start = index + 1;
    }
  }

  if (text.slice(start).trim() !== '') {
    statements.push({ prelude: text.slice(start).trim(), block: null });
  }

  return statements;
}

/**
 * The layer each stylesheet of the kit belongs in.
 *
 * @param {string} path
 * @returns {string}
 */
function layerOf(path) {
  if (path === 'js/ui-kit/src/tokens.css') {
    return 'cms.tokens';
  }

  if (path === 'js/ui-kit/src/base.css') {
    return 'cms.reset';
  }

  return 'cms.kit';
}

const stylesheets = kitFiles('.css').filter((path) => path !== 'js/ui-kit/src/layers.css');

void describe('the cascade layers', () => {
  void test('are declared once, in order, by layers.css and nothing else there', () => {
    assert.deepEqual(topLevel(read('js/ui-kit/src/layers.css')), [
      { prelude: `@layer ${LAYERS.join(', ')}`, block: null },
    ]);
    assert.deepEqual(LAYERS, [
      'cms.reset',
      'cms.tokens',
      'cms.addon',
      'cms.kit',
      'cms.panel',
      'cms.theme',
    ]);
  });

  void test('cover every stylesheet of the kit', () => {
    assert.ok(stylesheets.includes('js/ui-kit/src/tokens.css'));
    assert.ok(stylesheets.includes('js/ui-kit/src/base.css'));
    assert.ok(stylesheets.some((path) => path.startsWith('js/ui-kit/src/components/')));
  });

  for (const path of stylesheets) {
    void test(`hold every rule of ${path} in ${layerOf(path)}, without !important`, () => {
      const css = read(path);

      for (const statement of topLevel(css)) {
        assert.equal(statement.prelude, `@layer ${layerOf(path)}`, `${path}: an unlayered rule`);
        assert.notEqual(statement.block, null, `${path}: a layer statement outside layers.css`);
      }

      assert.doesNotMatch(css, /!\s*important/i, `${path}: !important`);
    });
  }

  void test('are declared before any other stylesheet the panel imports', () => {
    const imports = [...read('js/panel/src/app.tsx').matchAll(/^import '([^']+\.css)';$/gm)].map(
      (match) => match[1],
    );

    assert.deepEqual(imports.slice(0, 3), [
      '@cboxdk/cms-ui-kit/layers.css',
      '@cboxdk/cms-ui-kit/tokens.css',
      '@cboxdk/cms-ui-kit/base.css',
    ]);
  });

  void test('refuse an unlayered rule and a second order statement', () => {
    assert.deepEqual(topLevel('@layer cms.kit { .a { color: red; } }\n.b { color: red; }'), [
      { prelude: '@layer cms.kit', block: ' .a { color: red; } ' },
      { prelude: '.b', block: ' color: red; ' },
    ]);
    assert.deepEqual(topLevel('/* a; */ @layer a, b;'), [{ prelude: '@layer a, b', block: null }]);
  });
});

void describe('the tokens the kit reads', () => {
  const { catalogue: tokens } = catalogue();
  const byName = new Map(tokens.tokens.map((token) => [token.name, token]));
  const readers = stylesheets.filter((path) => path !== 'js/ui-kit/src/tokens.css');

  for (const path of readers) {
    void test(`exist and are not primitives in ${path}`, () => {
      for (const match of read(path).matchAll(/var\((--cms-[a-z0-9-]+)/g)) {
        const name = (match[1] ?? '').slice(PREFIX.length);
        const token = byName.get(name);

        assert.ok(token !== undefined, `${path}: ${PREFIX}${name} is not a token of tokens.json`);
        assert.notEqual(token.tier, 'primitive', `${path}: reads the primitive ${PREFIX}${name}`);
      }
    });
  }
});
