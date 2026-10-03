// The design tokens of the kit (js/ui-kit/tokens.json): the catalogue is well formed, every
// contrast pair it lists meets WCAG 2.2 AA in the light and the dark mode (4.5:1 for text, 3:1
// for a user interface part and the focus ring), the pointer target is at least 24 pixels, and the
// checks themselves refuse what they should. `npm run test:kit -- tokens` runs this file.

import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import {
  contrastProblems,
  contrastRatio,
  contrastResults,
  formatRatio,
  isLiteralValue,
  pixels,
  readCatalogue,
  resolvedValue,
} from '../scripts/tokens.js';
import { catalogue, TOKENS_FILE } from './kit.js';

const { catalogue: tokens, problems } = catalogue();

void describe(`the catalogue ${TOKENS_FILE}`, () => {
  void test('has no problems', () => {
    assert.deepEqual(problems, []);
  });

  void test('lists at least one text pair and one pair of a user interface part', () => {
    assert.ok(tokens.contrast.some((pair) => pair.kind === 'text'));
    assert.ok(tokens.contrast.some((pair) => pair.kind === 'ui'));
  });

  for (const { pair, mode, ratio, minimum } of contrastResults(tokens)) {
    void test(`${pair.foreground} on ${pair.background} (${pair.kind}) reaches ${formatRatio(minimum)} in the ${mode} mode`, () => {
      assert.ok(
        ratio >= minimum,
        `${pair.foreground} on ${pair.background} is ${formatRatio(ratio)} in the ${mode} mode, below ${formatRatio(minimum)}`,
      );
    });
  }

  void test('makes the pointer target at least 24 pixels', () => {
    for (const mode of /** @type {const} */ (['light', 'dark'])) {
      const size = pixels(resolvedValue(tokens, 'target-size', mode));

      assert.ok(size !== null && size >= 24, `--cms-target-size is ${String(size)} pixels`);
    }
  });
});

/** @typedef {Record<string, string>} PlantedToken */

/**
 * @typedef {object} PlantedCatalogue
 * @property {Record<string, PlantedToken>} tokens
 * @property {PlantedToken[]} contrast
 * @property {Record<string, never>} parts
 */

/**
 * A semantic colour token with one value.
 *
 * @param {string} value
 * @returns {PlantedToken}
 */
function colour(value) {
  return {
    tier: 'semantic',
    type: 'color',
    value,
    stability: 'stable',
    since: '1.0',
    description: 'A colour.',
  };
}

/**
 * A catalogue of a surface and a text colour with the text pair, and more tokens.
 *
 * @param {string} text
 * @param {Record<string, PlantedToken>} [extra]
 * @returns {PlantedCatalogue}
 */
function plantedCatalogue(text, extra = {}) {
  return {
    tokens: { 'color-surface': colour('#ffffff'), 'color-text': colour(text), ...extra },
    contrast: [{ foreground: 'color-text', background: 'color-surface', kind: 'text' }],
    parts: {},
  };
}

/**
 * The text of a planted catalogue.
 *
 * @param {string} text
 * @param {Record<string, PlantedToken>} [extra]
 * @returns {string}
 */
function planted(text, extra = {}) {
  return JSON.stringify(plantedCatalogue(text, extra));
}

void describe('the contrast check', () => {
  void test('measures black on white as 21:1 and a colour on itself as 1:1', () => {
    assert.equal(contrastRatio('#000000', '#ffffff'), 21);
    assert.equal(contrastRatio('#2f5bd3', '#2f5bd3'), 1);
  });

  void test('reads oklch the same as the hex of the same colour', () => {
    assert.ok(Math.abs(contrastRatio('oklch(100% 0 0)', 'oklch(0% 0 0)') - 21) < 0.01);
    assert.ok(Math.abs(contrastRatio('oklch(62.8% 0.2577 29.23)', '#ff0000') - 1) < 0.01);
  });

  void test('reports a text pair below 4.5:1, in each mode it fails', () => {
    const { catalogue: low, problems: none } = readCatalogue(planted('#8a919e'));

    assert.deepEqual(none, []);
    assert.deepEqual(contrastProblems(low), [
      'color-text on color-surface (text) is 3.17:1 in the light mode, below 4.50:1',
      'color-text on color-surface (text) is 3.17:1 in the dark mode, below 4.50:1',
    ]);
  });

  void test('reports a pair of a user interface part below 3:1 and passes one above it', () => {
    const pair = [{ foreground: 'color-border', background: 'color-surface', kind: 'ui' }];
    const below = {
      ...plantedCatalogue('#16181d', { 'color-border': colour('#a0a6b0') }),
      contrast: pair,
    };
    const above = {
      ...plantedCatalogue('#16181d', { 'color-border': colour('#8a919e') }),
      contrast: pair,
    };

    assert.equal(contrastProblems(readCatalogue(JSON.stringify(below)).catalogue).length, 2);
    assert.deepEqual(contrastProblems(readCatalogue(JSON.stringify(above)).catalogue), []);
  });

  void test('checks the dark value of a token with a value per mode', () => {
    const modal = plantedCatalogue('#16181d', {
      'color-text': {
        tier: 'semantic',
        type: 'color',
        light: '#16181d',
        dark: '#eeeeee',
        stability: 'stable',
        since: '1.0',
        description: 'A colour.',
      },
    });

    assert.deepEqual(contrastProblems(readCatalogue(JSON.stringify(modal)).catalogue), [
      'color-text on color-surface (text) is 1.16:1 in the dark mode, below 4.50:1',
    ]);
  });

  void test('never rounds a ratio up to its minimum', () => {
    assert.equal(formatRatio(4.4999), '4.49:1');
  });
});

void describe('the catalogue check', () => {
  /** @type {(entry: PlantedToken) => string[]} */
  const problemsOf = (entry) =>
    readCatalogue(planted('#16181d', { 'color-probe': entry })).problems.filter((problem) =>
      problem.startsWith('tokens.color-probe'),
    );
  const base = {
    tier: 'semantic',
    type: 'color',
    stability: 'stable',
    since: '1.0',
    description: 'A probe.',
  };

  void test('refuses a reference to an unknown token', () => {
    assert.deepEqual(problemsOf({ ...base, value: '{color-missing}' }), [
      'tokens.color-probe: refers to the unknown token "color-missing"',
    ]);
  });

  void test('refuses a value that is not of its type', () => {
    assert.deepEqual(problemsOf({ ...base, value: '12px' }), [
      'tokens.color-probe: the dark value "12px" is not a color',
      'tokens.color-probe: the light value "12px" is not a color',
    ]);
  });

  void test('refuses a semantic token that is internal, and one named as a primitive', () => {
    assert.deepEqual(problemsOf({ ...base, value: '#000000', stability: 'internal' }), [
      'tokens.color-probe: a primitive token, and only a primitive one, is internal',
    ]);
  });

  void test('refuses a reference to a higher tier, and a cycle', () => {
    const { problems: cycle } = readCatalogue(
      planted('{color-surface}', {
        'color-surface': { ...base, value: '{color-text}' },
      }),
    );

    assert.ok(cycle.some((problem) => problem.includes('refers to itself through')));
    assert.deepEqual(
      readCatalogue(
        planted('#16181d', {
          'button-probe': {
            ...base,
            tier: 'component',
            stability: 'experimental',
            value: '#000000',
          },
          'color-probe': { ...base, value: '{button-probe}' },
        }),
      ).problems,
      ['tokens.color-probe: refers to "button-probe" of the higher tier component'],
    );
  });

  void test('refuses a contrast pair of a token that is not a colour', () => {
    const json = plantedCatalogue('#16181d');
    json.contrast.push({ foreground: 'color-missing', background: 'color-surface', kind: 'text' });

    assert.deepEqual(readCatalogue(JSON.stringify(json)).problems, [
      'contrast[1]: "color-missing" is not a colour token',
    ]);
  });

  void test('takes the literal forms a theme may give', () => {
    assert.ok(isLiteralValue('color', '#2f5bd3'));
    assert.ok(isLiteralValue('color', 'oklch(55% 0.16 150)'));
    assert.ok(!isLiteralValue('color', 'red'));
    assert.ok(isLiteralValue('length', '1.5rem'));
    assert.ok(!isLiteralValue('length', '1.5'));
    assert.ok(isLiteralValue('font-family', "system-ui, -apple-system, 'Segoe UI', sans-serif"));
    assert.ok(isLiteralValue('shadow', '0 0 0 2px #ffffff, 0 0 0 4px #2f5bd3'));
  });
});
