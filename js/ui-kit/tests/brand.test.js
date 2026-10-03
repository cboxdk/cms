// The Cbox design language of the kit and the panel (Sylvester, 3 October 2026, PROGRESS.md
// "Panelets visuelle sprog" and "`@cboxdk/cbox-ui` bliver MIT"): the kit depends on
// @cboxdk/cbox-ui and takes its palette from the package's tokens/cbox.css; text is set in Plus
// Jakarta Sans and code in JetBrains Mono, self-hosted from the @fontsource-variable packages,
// never Inter, Roboto or a system face first; and no message, card or panel marks its tone with a
// coloured stripe at its left or inline-start edge. `npm run test:kit -- brand` runs this file.

import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join, relative } from 'node:path';
import { describe, test } from 'vitest';

import { linearRgb, resolvedValue } from '../scripts/tokens.js';
import { ROOT, catalogue, read } from './kit.js';

/** The package of the Cbox design language, and the minor version the kit follows. */
const CBOX_UI = '@cboxdk/cbox-ui';

/** The font packages the kit self-hosts its typefaces from, with the family each serves. */
const FONTS = {
  '@fontsource-variable/plus-jakarta-sans': 'Plus Jakarta Sans',
  '@fontsource-variable/jetbrains-mono': 'JetBrains Mono',
};

/** Faces that may never be the first choice of a font token. */
const FORBIDDEN_FIRST =
  /^(?:'?Inter'?|'?Roboto'?|system-ui|-apple-system|'Segoe UI'|ui-sans-serif|sans-serif)$/;

/**
 * The primitives of tokens.json that are copies of a custom property of @cboxdk/cbox-ui
 * tokens/cbox.css, with the property and the block it is read from (:root is light, .dark dark).
 * The other primitives are the same hues at another lightness, where the kit's contrast pairs
 * (WCAG 2.2 AA) need it, or a soft tone composited over the page.
 *
 * @type {Readonly<Record<string, readonly [string, 'light' | 'dark']>>}
 */
const FROM_CBOX_UI = {
  'ref-white': ['card', 'light'],
  'ref-gray-10': ['primary-foreground', 'light'],
  'ref-gray-25': ['background', 'light'],
  'ref-gray-50': ['canvas', 'light'],
  'ref-gray-75': ['muted', 'light'],
  'ref-gray-200': ['border', 'light'],
  'ref-gray-600': ['muted-foreground', 'light'],
  'ref-gray-900': ['foreground', 'light'],
  'ref-blue-600': ['primary', 'light'],
  'ref-gray-100': ['foreground', 'dark'],
  'ref-gray-350': ['muted-foreground', 'dark'],
  'ref-gray-800': ['border', 'dark'],
  'ref-gray-850': ['muted', 'dark'],
  'ref-gray-925': ['card', 'dark'],
  'ref-gray-940': ['canvas', 'dark'],
  'ref-gray-950': ['background', 'dark'],
  'ref-gray-1000': ['primary-foreground', 'dark'],
  'ref-blue-400': ['primary', 'dark'],
  'ref-red-400': ['destructive', 'dark'],
  'ref-green-400': ['success', 'dark'],
  'ref-amber-400': ['warning', 'dark'],
};

/** The colour tokens of a tone: the accent, the focus ring and the four tones, with their variants. */
const TONE_TOKEN = /--cms-color-(?:accent|focus|danger|success|warning|info)(?:-[a-z]+)*\b/;

/** A colour written as a literal or a system colour rather than through a token. */
const LITERAL_COLOUR =
  /#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\(|\b(?:Highlight|Mark|AccentColor|LinkText|red|green|blue|orange|yellow)\b/i;

/**
 * Each declaration of a stylesheet that draws a coloured stripe at the left or inline-start edge:
 * a border-left or border-inline-start (or its colour, width or style) in a tone or a literal
 * colour, or an inset box shadow offset from that edge in one. A quiet 1px divider in a border
 * token is not a stripe.
 *
 * @param {string} css
 * @returns {string[]} the declarations, as written
 */
export function edgeStripes(css) {
  const text = css.replace(/\/\*[\s\S]*?\*\//g, '');
  /** @type {string[]} */
  const stripes = [];

  for (const match of text.matchAll(/([a-z-]+)\s*:\s*([^;{}]+)/g)) {
    const property = match[1] ?? '';
    const value = match[2] ?? '';
    const coloured = TONE_TOKEN.test(value) || LITERAL_COLOUR.test(value);
    const edge = /^border-(?:left|inline-start)(?:-color|-width|-style)?$/.test(property);
    const insetShadow =
      property === 'box-shadow' &&
      /\binset\s+(?:[1-9]\d*(?:\.\d+)?px|\d*\.\d+(?:px|rem|em))\s+0\b/.test(value);

    if ((edge || insetShadow) && coloured) {
      stripes.push(`${property}: ${value.trim()}`);
    }
  }

  return stripes;
}

/**
 * Every stylesheet below a directory of the repository, relative to the root, sorted.
 *
 * @param {string} directory relative to the root
 * @returns {string[]}
 */
function stylesheetsBelow(directory) {
  return readdirSync(join(ROOT, directory), { recursive: true, encoding: 'utf8' })
    .filter((file) => file.endsWith('.css'))
    .map((file) => relative(ROOT, join(ROOT, directory, file)))
    .sort();
}

/**
 * The custom properties of one block of cbox.css, such as :root or .dark.
 *
 * @param {string} css
 * @param {string} selector
 * @returns {Map<string, string>}
 */
function cboxBlock(css, selector) {
  const start = css.indexOf(`${selector} {`);

  assert.notEqual(start, -1, `tokens/cbox.css has no ${selector} block`);

  const block = css.slice(start, css.indexOf('}', start));

  return new Map(
    [...block.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)].map((match) => [
      match[1] ?? '',
      (match[2] ?? '').trim(),
    ]),
  );
}

/**
 * A colour of cbox.css, oklch(L C H) with L from 0 to 1, in the form of the kit's catalogue.
 *
 * @param {string} value
 * @returns {string}
 */
function asCatalogueColour(value) {
  const match = /^oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)$/.exec(value);

  assert.ok(match !== null, `"${value}" is not an opaque oklch colour`);

  const lightness = String(Number((Number(match[1]) * 100).toFixed(4)));

  return `oklch(${lightness}% ${match[2] ?? ''} ${match[3] ?? ''})`;
}

/**
 * @param {string} first
 * @param {string} second
 * @returns {boolean}
 */
function sameColour(first, second) {
  const [a, b] = [linearRgb(first), linearRgb(second)];

  return a.every((channel, index) => Math.abs(channel - (b[index] ?? -1)) < 0.001);
}

/**
 * The dependencies of the kit's package.json.
 *
 * @returns {Record<string, unknown>}
 */
function kitDependencies() {
  /** @type {unknown} */
  const manifest = JSON.parse(read('js/ui-kit/package.json'));
  /** @type {unknown} */
  const dependencies =
    typeof manifest === 'object' && manifest !== null
      ? Reflect.get(manifest, 'dependencies')
      : undefined;

  return typeof dependencies === 'object' && dependencies !== null
    ? /** @type {Record<string, unknown>} */ (dependencies)
    : {};
}

const require = createRequire(join(ROOT, 'js/ui-kit/package.json'));

describe('the Cbox design language', () => {
  const { catalogue: tokens } = catalogue();

  test(`makes the kit depend on ${CBOX_UI} 0.6 and on the font packages it self-hosts`, () => {
    const dependencies = kitDependencies();

    assert.match(
      String(dependencies[CBOX_UI]),
      /^\^?0\.6\.\d+$/,
      `${CBOX_UI} is not a 0.6 dependency`,
    );

    for (const name of Object.keys(FONTS)) {
      assert.ok(typeof dependencies[name] === 'string', `${name} is not a dependency of the kit`);
    }
  });

  test('sets text and headings in Plus Jakarta Sans and code in JetBrains Mono', () => {
    for (const mode of /** @type {const} */ (['light', 'dark'])) {
      for (const name of ['font-family', 'font-family-display']) {
        const families = resolvedValue(tokens, name, mode).split(', ');

        assert.equal(
          families[0],
          "'Plus Jakarta Sans'",
          `${name} does not start with Plus Jakarta Sans`,
        );
        assert.ok(
          !families.some((family) => /Inter|Roboto/.test(family)),
          `${name} names Inter or Roboto`,
        );
      }

      const mono = resolvedValue(tokens, 'font-family-mono', mode).split(', ');

      assert.equal(
        mono[0],
        "'JetBrains Mono'",
        'font-family-mono does not start with JetBrains Mono',
      );
      assert.ok(
        !mono.some((family) => /Inter|Roboto/.test(family)),
        'font-family-mono names Inter or Roboto',
      );
    }

    for (const token of tokens.tokens.filter((each) => each.type === 'font-family')) {
      const first = resolvedValue(tokens, token.name, 'light').split(', ')[0] ?? '';

      assert.doesNotMatch(first, FORBIDDEN_FIRST, `${token.name} starts with ${first}`);
    }
  });

  test('writes the faces first into the custom properties of tokens.css', () => {
    const css = read('js/ui-kit/src/tokens.css');

    assert.match(css, /--cms-font-family:\s*'Plus Jakarta Sans',/);
    assert.match(css, /--cms-font-family-mono:\s*'JetBrains Mono',/);
  });

  test('self-hosts both faces as woff2 from the font packages, never from another host', () => {
    const css = read('js/ui-kit/src/base.css');
    const faces = [...css.matchAll(/@font-face\s*\{([^}]*)\}/g)].map((match) => match[1] ?? '');

    for (const [name, family] of Object.entries(FONTS)) {
      const own = faces.filter((face) => face.includes(`font-family: '${family}';`));

      assert.ok(own.length > 0, `base.css declares no @font-face for ${family}`);

      for (const face of own) {
        const sources = [...face.matchAll(/url\('([^']+)'\)/g)].map((match) => match[1] ?? '');

        assert.ok(sources.length > 0, `a @font-face of ${family} has no source`);

        for (const source of sources) {
          assert.ok(source.startsWith(`${name}/files/`), `${source} is not a file of ${name}`);
          assert.ok(source.endsWith('.woff2'), `${source} is not a woff2 file`);
          assert.doesNotThrow(() => require.resolve(source), `${source} is not installed`);
        }
      }
    }

    assert.doesNotMatch(
      css,
      /url\(\s*['"]?(?:https?:)?\/\//,
      'base.css loads a font from another host',
    );
  });

  test(`takes the palette of ${CBOX_UI} tokens/cbox.css`, () => {
    const cbox = readFileSync(require.resolve(`${CBOX_UI}/tokens`), 'utf8');
    const blocks = { light: cboxBlock(cbox, ':root'), dark: cboxBlock(cbox, '.dark') };

    for (const [primitive, [property, mode]] of Object.entries(FROM_CBOX_UI)) {
      const source = blocks[mode].get(property);

      assert.ok(source !== undefined, `tokens/cbox.css has no --${property} in ${mode}`);

      const kit = resolvedValue(tokens, primitive, mode);

      assert.ok(
        sameColour(kit, asCatalogueColour(source)),
        `${primitive} is ${kit}, not --${property} of ${CBOX_UI} in ${mode} (${source})`,
      );
    }
  });
});

describe('the edges of messages, cards and panels', () => {
  const stylesheets = [...stylesheetsBelow('js/ui-kit/src'), ...stylesheetsBelow('js/panel/src')];

  test('are checked in every stylesheet of the kit', () => {
    assert.ok(stylesheets.includes('js/ui-kit/src/components/callout.css'));
  });

  for (const path of stylesheets) {
    test(`carry no coloured stripe at the left or inline-start edge in ${path}`, () => {
      assert.deepEqual(
        edgeStripes(read(path)),
        [],
        `${path} marks a tone with a stripe at its edge`,
      );
    });
  }

  test('are found in a planted stripe of each form and left alone for a quiet divider', () => {
    assert.deepEqual(
      edgeStripes(
        [
          '.a { border-inline-start: 4px solid var(--cms-color-danger); }',
          '.b { border-left: 3px solid #c4302b; }',
          '.c { border-inline-start-color: var(--cms-color-accent); }',
          '.d { border-left-color: Highlight; }',
          '.e { box-shadow: inset 4px 0 0 var(--cms-color-warning); }',
          '/* .f { border-left: 4px solid var(--cms-color-danger); } */',
          '.g { border-inline-start: 1px solid var(--cms-color-border); }',
          '.h { box-shadow: inset 0 -2px 0 var(--cms-color-focus); }',
        ].join('\n'),
      ),
      [
        'border-inline-start: 4px solid var(--cms-color-danger)',
        'border-left: 3px solid #c4302b',
        'border-inline-start-color: var(--cms-color-accent)',
        'border-left-color: Highlight',
        'box-shadow: inset 4px 0 0 var(--cms-color-warning)',
      ],
    );
  });
});
