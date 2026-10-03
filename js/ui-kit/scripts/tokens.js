// The design tokens of the component kit, read from js/ui-kit/tokens.json, the single source of
// tokens.css, the TypeScript names, the JSON Schema of a theme and docs/ui/tokens.md
// (generate-tokens.js writes them, and gate 6 holds them to the JSON). This module reads and checks
// the catalogue, resolves a token's value in the light and the dark mode, measures the contrast of
// the pairs the catalogue lists (WCAG 2.2: 4.5:1 for text, 3:1 for a user interface part or the
// focus ring), and renders each generated file. It has no dependencies, so the tests and the
// generator share it.

/** @typedef {'primitive' | 'semantic' | 'component'} Tier */
/** @typedef {'color' | 'length' | 'duration' | 'number' | 'font-family' | 'shadow'} ValueType */
/** @typedef {'internal' | 'experimental' | 'stable'} Stability */
/** @typedef {'light' | 'dark'} Mode */
/** @typedef {'text' | 'ui'} ContrastKind */

/**
 * @typedef {object} Token
 * @property {string} name The name without the `--cms-` prefix of its custom property.
 * @property {Tier} tier
 * @property {ValueType} type
 * @property {string} light The value in the light mode, as written in the catalogue.
 * @property {string} dark The value in the dark mode, the light value when the token has one value.
 * @property {boolean} modal Whether the token has a value of its own per mode.
 * @property {Stability} stability
 * @property {string} since
 * @property {string} description
 */

/**
 * @typedef {object} ContrastPair
 * @property {string} foreground
 * @property {string} background
 * @property {ContrastKind} kind
 */

/**
 * @typedef {object} Part
 * @property {string} name The value of the `data-cms-part` attribute.
 * @property {string} since
 * @property {string} description
 */

/**
 * @typedef {object} Catalogue
 * @property {Token[]} tokens In the order of the catalogue.
 * @property {ContrastPair[]} contrast
 * @property {Part[]} parts Sorted by name.
 */

/**
 * @typedef {object} ContrastResult
 * @property {ContrastPair} pair
 * @property {Mode} mode
 * @property {number} ratio
 * @property {number} minimum
 */

/** The prefix of every custom property of the kit. */
export const PREFIX = '--cms-';

/** The order of the cascade layers, declared once by js/ui-kit/src/layers.css. */
export const LAYERS = ['cms.reset', 'cms.tokens', 'cms.addon', 'cms.kit', 'cms.panel', 'cms.theme'];

/** @type {readonly Tier[]} */
export const TIERS = ['primitive', 'semantic', 'component'];

/** @type {readonly ValueType[]} */
export const VALUE_TYPES = ['color', 'length', 'duration', 'number', 'font-family', 'shadow'];

/** @type {readonly Mode[]} */
export const MODES = ['light', 'dark'];

/** The lowest contrast ratio of each kind of pair (WCAG 2.2, 1.4.3 and 1.4.11). */
export const MINIMUM_CONTRAST = { text: 4.5, ui: 3 };

/** The smallest pointer target in pixels (WCAG 2.2, 2.5.8), which --cms-target-size must reach. */
export const MINIMUM_TARGET_PIXELS = 24;

const NAME = /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/;
const SINCE = /^\d+\.\d+$/;
const REFERENCE = /^\{([a-z0-9-]+)\}$/;
const EMBEDDED_REFERENCE = /\{([a-z0-9-]+)\}/g;
const HEX = /^#[0-9a-f]{6}$/;
const OKLCH = /^oklch\((\d+(?:\.\d+)?)% (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)\)$/;
const FAMILY = "(?:-?[A-Za-z][A-Za-z0-9-]*|'[A-Za-z0-9 -]+')";
const SHADOW_OFFSETS = '(?:inset )?(?:-?\\d+(?:\\.\\d+)?(?:px)? ){2,4}';
const SHADOW_LAYER = new RegExp(`^${SHADOW_OFFSETS}(?:\\{[a-z0-9-]+\\}|#[0-9a-f]{6})$`);

/** The pattern a literal value of each type must match, in the catalogue and in a theme. */
export const VALUE_PATTERNS = {
  color: '^(?:#[0-9a-f]{6}|oklch\\(\\d+(?:\\.\\d+)?% \\d+(?:\\.\\d+)? \\d+(?:\\.\\d+)?\\))$',
  length: '^(?:0|\\d+(?:\\.\\d+)?(?:px|rem|em|%))$',
  duration: '^\\d+ms$',
  number: '^\\d+(?:\\.\\d+)?$',
  'font-family': `^${FAMILY}(?:, ${FAMILY})*$`,
  shadow: `^${SHADOW_OFFSETS}#[0-9a-f]{6}(?:, ${SHADOW_OFFSETS}#[0-9a-f]{6})*$`,
};

/**
 * @param {unknown} value
 * @returns {value is Record<string, unknown>}
 */
function isObject(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * @template {string} T
 * @param {readonly T[]} options
 * @param {unknown} value
 * @returns {value is T}
 */
function isOneOf(options, value) {
  return typeof value === 'string' && /** @type {readonly string[]} */ (options).includes(value);
}

/**
 * Whether a literal value, without references, has the form of its type.
 *
 * @param {ValueType} type
 * @param {string} value
 * @returns {boolean}
 */
export function isLiteralValue(type, value) {
  return new RegExp(VALUE_PATTERNS[type]).test(value);
}

/**
 * Whether a value written in the catalogue has the form of its type: a reference to one token, a
 * literal, or for a shadow layers whose colour may be a reference.
 *
 * @param {ValueType} type
 * @param {string} value
 * @returns {boolean}
 */
function isCatalogueValue(type, value) {
  if (REFERENCE.test(value)) {
    return true;
  }

  if (type === 'shadow') {
    return value.split(', ').every((layer) => SHADOW_LAYER.test(layer));
  }

  return isLiteralValue(type, value);
}

/**
 * The names of the tokens a value refers to.
 *
 * @param {string} value
 * @returns {string[]}
 */
function referencesOf(value) {
  return [...value.matchAll(EMBEDDED_REFERENCE)].map((match) => match[1] ?? '');
}

/**
 * Reads one token entry, adding what is wrong with it to the problems.
 *
 * @param {string} name
 * @param {unknown} entry
 * @param {string[]} problems
 * @returns {Token | null}
 */
function readToken(name, entry, problems) {
  const at = `tokens.${name}`;

  if (!NAME.test(name)) {
    problems.push(`${at}: the name is not lower-case words joined by hyphens`);
  }

  if (!isObject(entry)) {
    problems.push(`${at}: not an object`);

    return null;
  }

  const known = ['tier', 'type', 'value', 'light', 'dark', 'stability', 'since', 'description'];

  for (const key of Object.keys(entry)) {
    if (!known.includes(key)) {
      problems.push(`${at}: the key "${key}" is not one of ${known.join(', ')}`);
    }
  }

  const { tier, type, value, light, dark, stability, since, description } = entry;

  if (!isOneOf(TIERS, tier)) {
    problems.push(`${at}: the tier is not one of ${TIERS.join(', ')}`);

    return null;
  }

  if (!isOneOf(VALUE_TYPES, type)) {
    problems.push(`${at}: the type is not one of ${VALUE_TYPES.join(', ')}`);

    return null;
  }

  if (!isOneOf(['internal', 'experimental', 'stable'], stability)) {
    problems.push(`${at}: the stability is not one of internal, experimental, stable`);

    return null;
  }

  if (typeof since !== 'string' || !SINCE.test(since)) {
    problems.push(`${at}: since is not a version such as "1.0"`);
  }

  if (typeof description !== 'string' || description.trim() === '') {
    problems.push(`${at}: the description is missing`);
  }

  if ((tier === 'primitive') !== name.startsWith('ref-')) {
    problems.push(`${at}: a primitive token, and only a primitive one, is named ref-*`);
  }

  if ((tier === 'primitive') !== (stability === 'internal')) {
    problems.push(`${at}: a primitive token, and only a primitive one, is internal`);
  }

  /** @type {[string, string]} */
  let values;

  if (typeof value === 'string' && light === undefined && dark === undefined) {
    values = [value, value];
  } else if (typeof light === 'string' && typeof dark === 'string' && value === undefined) {
    values = [light, dark];

    if (light === dark) {
      problems.push(`${at}: the light and the dark value are the same; write it once as value`);
    }
  } else {
    problems.push(`${at}: give either value, or both light and dark, as strings`);

    return null;
  }

  for (const [mode, written] of [
    ['light', values[0]],
    ['dark', values[1]],
  ]) {
    if (!isCatalogueValue(type, written ?? '')) {
      problems.push(`${at}: the ${mode ?? ''} value "${written ?? ''}" is not a ${type}`);
    }
  }

  return {
    name,
    tier,
    type,
    light: values[0],
    dark: values[1],
    modal: values[0] !== values[1],
    stability,
    since: typeof since === 'string' ? since : '',
    description: typeof description === 'string' ? description : '',
  };
}

/**
 * Checks the references of every token: each names a token of the same or a lower tier, whole
 * values name a token of the same type and a shadow's colour a colour, a primitive refers to
 * nothing, and no token refers to itself through others.
 *
 * @param {Token[]} tokens
 * @param {string[]} problems
 * @returns {void}
 */
function checkReferences(tokens, problems) {
  const byName = new Map(tokens.map((token) => [token.name, token]));

  for (const token of tokens) {
    for (const value of new Set([token.light, token.dark])) {
      const whole = REFERENCE.exec(value);

      for (const name of referencesOf(value)) {
        const target = byName.get(name);
        const at = `tokens.${token.name}`;

        if (target === undefined) {
          problems.push(`${at}: refers to the unknown token "${name}"`);
        } else if (token.tier === 'primitive') {
          problems.push(`${at}: a primitive token refers to no other token`);
        } else if (TIERS.indexOf(target.tier) > TIERS.indexOf(token.tier)) {
          problems.push(`${at}: refers to "${name}" of the higher tier ${target.tier}`);
        } else if (whole !== null && target.type !== token.type) {
          problems.push(`${at}: refers to "${name}", a ${target.type}, as a ${token.type}`);
        } else if (whole === null && target.type !== 'color') {
          problems.push(`${at}: the colour of a shadow refers to "${name}", a ${target.type}`);
        }
      }
    }
  }

  for (const token of tokens) {
    for (const mode of MODES) {
      try {
        resolve(byName, token.name, mode, []);
      } catch (error) {
        if (error instanceof CycleError) {
          problems.push(`tokens.${token.name}: ${error.message}`);
          break;
        }

        throw error;
      }
    }
  }
}

class CycleError extends Error {}

/**
 * The value of a token in a mode with every reference replaced by the value it names.
 *
 * @param {Map<string, Token>} byName
 * @param {string} name
 * @param {Mode} mode
 * @param {string[]} path
 * @returns {string}
 */
function resolve(byName, name, mode, path) {
  if (path.includes(name)) {
    throw new CycleError(`refers to itself through ${[...path, name].join(' > ')}`);
  }

  const token = byName.get(name);

  if (token === undefined) {
    return '';
  }

  return token[mode].replace(EMBEDDED_REFERENCE, (_match, target) =>
    resolve(byName, String(target), mode, [...path, name]),
  );
}

/**
 * Reads the catalogue from the text of tokens.json and returns it with every problem, sorted; a
 * catalogue with problems must not be rendered.
 *
 * @param {string} text
 * @returns {{ catalogue: Catalogue, problems: string[] }}
 */
export function readCatalogue(text) {
  /** @type {string[]} */
  const problems = [];
  /** @type {Catalogue} */
  const catalogue = { tokens: [], contrast: [], parts: [] };
  /** @type {unknown} */
  let decoded;

  try {
    decoded = JSON.parse(text);
  } catch (error) {
    problems.push(`not valid JSON (${error instanceof Error ? error.message : 'unknown'})`);

    return { catalogue, problems };
  }

  if (!isObject(decoded)) {
    return { catalogue, problems: ['not a JSON object'] };
  }

  for (const key of Object.keys(decoded)) {
    if (!['tokens', 'contrast', 'parts'].includes(key)) {
      problems.push(`the key "${key}" is not one of tokens, contrast, parts`);
    }
  }

  const { tokens, contrast, parts } = decoded;

  if (!isObject(tokens)) {
    problems.push('tokens: not an object of tokens by name');
  } else {
    for (const [name, entry] of Object.entries(tokens)) {
      const token = readToken(name, entry, problems);

      if (token !== null) {
        catalogue.tokens.push(token);
      }
    }

    checkReferences(catalogue.tokens, problems);
  }

  const byName = new Map(catalogue.tokens.map((token) => [token.name, token]));

  if (!Array.isArray(contrast)) {
    problems.push('contrast: not a list of pairs');
  } else {
    contrast.forEach((/** @type {unknown} */ entry, index) => {
      const at = `contrast[${String(index)}]`;

      if (
        !isObject(entry) ||
        typeof entry.foreground !== 'string' ||
        typeof entry.background !== 'string' ||
        !isOneOf(['text', 'ui'], entry.kind) ||
        Object.keys(entry).length !== 3
      ) {
        problems.push(`${at}: give exactly foreground, background and kind (text or ui)`);

        return;
      }

      for (const side of [entry.foreground, entry.background]) {
        if (byName.get(side)?.type !== 'color') {
          problems.push(`${at}: "${side}" is not a colour token`);
        }
      }

      catalogue.contrast.push({
        foreground: entry.foreground,
        background: entry.background,
        kind: entry.kind,
      });
    });
  }

  if (!isObject(parts)) {
    problems.push('parts: not an object of parts by name');
  } else {
    for (const [name, entry] of Object.entries(parts)) {
      const at = `parts.${name}`;

      if (!NAME.test(name)) {
        problems.push(`${at}: the name is not lower-case words joined by hyphens`);
      }

      if (
        !isObject(entry) ||
        typeof entry.since !== 'string' ||
        !SINCE.test(entry.since) ||
        typeof entry.description !== 'string' ||
        entry.description.trim() === '' ||
        Object.keys(entry).length !== 2
      ) {
        problems.push(`${at}: give exactly since (such as "1.0") and a description`);

        continue;
      }

      catalogue.parts.push({ name, since: entry.since, description: entry.description });
    }

    catalogue.parts.sort((a, b) => a.name.localeCompare(b.name));
  }

  return { catalogue, problems: problems.sort() };
}

/**
 * The value of a token in a mode with every reference resolved, such as "#ffffff".
 *
 * @param {Catalogue} catalogue
 * @param {string} name
 * @param {Mode} mode
 * @returns {string}
 */
export function resolvedValue(catalogue, name, mode) {
  return resolve(new Map(catalogue.tokens.map((token) => [token.name, token])), name, mode, []);
}

/**
 * The channels of a colour in linear sRGB, each from 0 to 1, from #rrggbb or oklch(L% C H); a
 * colour outside sRGB is clipped to it.
 *
 * @param {string} colour
 * @returns {[number, number, number]}
 */
export function linearRgb(colour) {
  if (HEX.test(colour)) {
    /** @type {(offset: number) => number} */
    const channel = (offset) => {
      const value = parseInt(colour.slice(offset, offset + 2), 16) / 255;

      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return [channel(1), channel(3), channel(5)];
  }

  const match = OKLCH.exec(colour);

  if (match === null) {
    throw new Error(`"${colour}" is not a colour of the form #rrggbb or oklch(L% C H).`);
  }

  const lightness = Number(match[1]) / 100;
  const chroma = Number(match[2]);
  const hue = (Number(match[3]) * Math.PI) / 180;
  const a = chroma * Math.cos(hue);
  const b = chroma * Math.sin(hue);
  const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;
  /** @type {(value: number) => number} */
  const clip = (value) => Math.min(1, Math.max(0, value));

  return [
    clip(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
    clip(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
    clip(-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s),
  ];
}

/**
 * The relative luminance of a colour (WCAG 2.2).
 *
 * @param {string} colour
 * @returns {number}
 */
export function luminance(colour) {
  const [red, green, blue] = linearRgb(colour);

  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

/**
 * The contrast ratio of two colours (WCAG 2.2), from 1 to 21.
 *
 * @param {string} first
 * @param {string} second
 * @returns {number}
 */
export function contrastRatio(first, second) {
  const [lighter, darker] = [luminance(first), luminance(second)].sort((x, y) => y - x);

  return ((lighter ?? 0) + 0.05) / ((darker ?? 0) + 0.05);
}

/**
 * The contrast of every listed pair in both modes, in the order of the catalogue.
 *
 * @param {Catalogue} catalogue
 * @returns {ContrastResult[]}
 */
export function contrastResults(catalogue) {
  return catalogue.contrast.flatMap((pair) =>
    MODES.map((mode) => ({
      pair,
      mode,
      ratio: contrastRatio(
        resolvedValue(catalogue, pair.foreground, mode),
        resolvedValue(catalogue, pair.background, mode),
      ),
      minimum: MINIMUM_CONTRAST[pair.kind],
    })),
  );
}

/**
 * A ratio as WCAG writes it, rounded down to two decimals so a ratio below the minimum never
 * shows as the minimum.
 *
 * @param {number} ratio
 * @returns {string}
 */
export function formatRatio(ratio) {
  return `${(Math.floor(ratio * 100) / 100).toFixed(2)}:1`;
}

/**
 * Every listed pair below its minimum, as a sentence each; none means the catalogue passes.
 *
 * @param {Catalogue} catalogue
 * @returns {string[]}
 */
export function contrastProblems(catalogue) {
  return contrastResults(catalogue)
    .filter((result) => result.ratio < result.minimum)
    .map(
      ({ pair, mode, ratio, minimum }) =>
        `${pair.foreground} on ${pair.background} (${pair.kind}) is ${formatRatio(ratio)} in the ${mode} mode, below ${formatRatio(minimum)}`,
    );
}

/**
 * The size of a resolved length in pixels, with 1rem and 1em as 16 pixels, or null for a
 * percentage.
 *
 * @param {string} length
 * @returns {number | null}
 */
export function pixels(length) {
  const match = /^(\d+(?:\.\d+)?)(px|rem|em)?$/.exec(length);

  if (match === null) {
    return null;
  }

  return Number(match[1]) * (match[2] === 'rem' || match[2] === 'em' ? 16 : 1);
}

/**
 * The CSS value of a token as written: each reference becomes the custom property it names.
 *
 * @param {string} value
 * @returns {string}
 */
function cssValue(value) {
  return value.replace(EMBEDDED_REFERENCE, (_match, name) => `var(${PREFIX}${String(name)})`);
}

/** The first line of every generated file that takes a comment. */
const NOTICE =
  'Generated by `npm run generate:tokens` from js/ui-kit/tokens.json. Do not edit it by hand: edit the JSON and run the script again; gate 6 fails when this file differs from what it writes.';

/**
 * tokens.css: every token as a custom property of the root in the layer cms.tokens, the dark
 * values when the system prefers dark and the root does not set data-theme="light" or when it
 * sets data-theme="dark", and every duration at 0 when the reader prefers reduced motion.
 *
 * @param {Catalogue} catalogue
 * @returns {string}
 */
export function renderCss(catalogue) {
  /** @type {(mode: Mode, tokens: Token[]) => string[]} */
  const declarations = (mode, tokens) =>
    tokens.map((token) => `${PREFIX}${token.name}: ${cssValue(token[mode])};`);
  const modal = catalogue.tokens.filter((token) => token.modal);
  const durations = catalogue.tokens.filter((token) => token.type === 'duration');

  return [
    `/* ${NOTICE} */`,
    '',
    '@layer cms.tokens {',
    ':root {',
    ...declarations('light', catalogue.tokens),
    'color-scheme: light dark;',
    '}',
    '',
    '@media (prefers-color-scheme: dark) {',
    ":root:not([data-theme='light']) {",
    ...declarations('dark', modal),
    '}',
    '}',
    '',
    ":root[data-theme='light'] {",
    'color-scheme: light;',
    '}',
    '',
    ":root[data-theme='dark'] {",
    'color-scheme: dark;',
    ...declarations('dark', modal),
    '}',
    '',
    '@media (prefers-reduced-motion: reduce) {',
    ':root {',
    ...durations.map((token) => `${PREFIX}${token.name}: 0ms;`),
    '}',
    '}',
    '}',
    '',
  ].join('\n');
}

/**
 * @param {string[]} names
 * @returns {string}
 */
function union(names) {
  return names.length === 0 ? 'never' : names.map((name) => `'${name}'`).join(' | ');
}

/**
 * The tokens a theme may set: the semantic and the component tiers.
 *
 * @param {Catalogue} catalogue
 * @returns {Token[]}
 */
export function themeTokens(catalogue) {
  return catalogue.tokens.filter((token) => token.tier !== 'primitive');
}

/**
 * src/generated/tokens.ts: the names of the tokens, of those a theme may set, and of the parts.
 *
 * @param {Catalogue} catalogue
 * @returns {string}
 */
export function renderTypeScript(catalogue) {
  return [
    `// ${NOTICE}`,
    '',
    '/**',
    ' * The name of a design token of the kit, without the `--cms-` prefix of its custom property.',
    ' *',
    ' * @stable',
    ' */',
    `export type TokenName = ${union(catalogue.tokens.map((token) => token.name))};`,
    '',
    '/**',
    ' * A token a theme may set: the semantic and the component tiers, never a primitive.',
    ' *',
    ' * @stable',
    ' */',
    `export type ThemeTokenName = ${union(themeTokens(catalogue).map((token) => token.name))};`,
    '',
    '/**',
    ' * A curated part hook, the value of a `data-cms-part` attribute, which only the theme layer',
    ' * may target.',
    ' *',
    ' * @experimental',
    ' */',
    `export type PartName = ${union(catalogue.parts.map((part) => part.name))};`,
    '',
  ].join('\n');
}

/**
 * src/generated/theme.v1.json: the JSON Schema a theme is validated against. A theme sets
 * literal values of the semantic and component tokens, one for both modes or one per mode, for
 * the whole panel under tokens and for a curated part under parts.
 *
 * @param {Catalogue} catalogue
 * @returns {string}
 */
export function renderThemeSchema(catalogue) {
  /** @type {Record<string, unknown>} */
  const definitions = {};

  for (const type of VALUE_TYPES) {
    const literal = { type: 'string', pattern: VALUE_PATTERNS[type] };
    definitions[type] = {
      anyOf: [
        literal,
        {
          type: 'object',
          additionalProperties: false,
          required: ['light', 'dark'],
          properties: { light: literal, dark: literal },
        },
      ],
    };
  }

  definitions.tokens = {
    type: 'object',
    additionalProperties: false,
    properties: Object.fromEntries(
      themeTokens(catalogue).map((token) => [
        token.name,
        { description: token.description, $ref: `#/$defs/${token.type}` },
      ]),
    ),
  };

  const schema = {
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    $id: 'urn:cbox-cms:theme.v1',
    title: 'A theme of the Cbox CMS panel',
    description: NOTICE,
    type: 'object',
    additionalProperties: false,
    properties: {
      tokens: { $ref: '#/$defs/tokens' },
      parts: {
        type: 'object',
        additionalProperties: false,
        properties: Object.fromEntries(
          catalogue.parts.map((part) => [
            part.name,
            { description: part.description, $ref: '#/$defs/tokens' },
          ]),
        ),
      },
    },
    $defs: definitions,
  };

  return `${JSON.stringify(schema, null, 2)}\n`;
}

/**
 * A value for a table cell of the docs page: as written, and resolved when it refers to tokens.
 *
 * @param {Catalogue} catalogue
 * @param {Token} token
 * @param {Mode} mode
 * @returns {string}
 */
function documentedValue(catalogue, token, mode) {
  const written = token[mode];
  const resolved = resolvedValue(catalogue, token.name, mode);

  return written === resolved ? `\`${written}\`` : `\`${resolved}\` (\`${cssValue(written)}\`)`;
}

/**
 * docs/ui/tokens.md: every token with its tier, type, values, stability and version, the
 * contrast of every pair in both modes, and the part hooks.
 *
 * @param {Catalogue} catalogue
 * @returns {string}
 */
export function renderDocs(catalogue) {
  const rows = catalogue.tokens.map(
    (token) =>
      `| \`${PREFIX}${token.name}\` | ${token.tier} | ${token.type} | ${documentedValue(catalogue, token, 'light')} | ${documentedValue(catalogue, token, 'dark')} | ${token.stability} | ${token.since} | ${token.description} |`,
  );
  const results = contrastResults(catalogue);
  const pairs = catalogue.contrast.map((pair) => {
    const [light, dark] = MODES.map((mode) =>
      formatRatio(
        results.find((result) => result.pair === pair && result.mode === mode)?.ratio ?? 0,
      ),
    );

    return `| \`${PREFIX}${pair.foreground}\` | \`${PREFIX}${pair.background}\` | ${pair.kind} | ${formatRatio(MINIMUM_CONTRAST[pair.kind])} | ${light ?? ''} | ${dark ?? ''} |`;
  });
  const parts = catalogue.parts.map(
    (part) => `| \`${part.name}\` | experimental | ${part.since} | ${part.description} |`,
  );

  return [
    '---',
    'title: Design tokens',
    'weight: 36',
    'description: Every design token of the component kit with its tier, values and stability, the contrast pairs the kit checks in the light and the dark mode, and the curated part hooks.',
    '---',
    '',
    '# Design tokens',
    '',
    '`npm run generate:tokens` writes this page from `js/ui-kit/tokens.json`, together with `js/ui-kit/src/tokens.css`, the TypeScript names in `js/ui-kit/src/generated/tokens.ts` and the JSON Schema of a theme in `js/ui-kit/src/generated/theme.v1.json`. Gate 6, `composer check:generated`, fails when one of them differs from what the script writes. Edit the JSON and run the script; do not edit the page by hand.',
    '',
    '## Tiers',
    '',
    '- **Primitive** tokens, named `--cms-ref-*`, are the palette. They are internal: only the kit sets them, and a component never reads one.',
    '- **Semantic** tokens, such as `--cms-color-surface` and `--cms-space-4`, say what a value is for. Components read them, and a theme may set them.',
    '- **Component** tokens, such as `--cms-button-radius`, belong to one component. They are experimental, and a theme may set them.',
    '',
    'Each custom property is set on the root in the cascade layer `cms.tokens`. A token with a value per mode takes its dark value when the system prefers dark and the root does not have `data-theme="light"`, or when the root has `data-theme="dark"`. Every duration is 0 when the reader prefers reduced motion.',
    '',
    '## Tokens',
    '',
    '| Token | Tier | Type | Light | Dark | Stability | Since | Use |',
    '|---|---|---|---|---|---|---|---|',
    ...rows,
    '',
    '## Contrast pairs',
    '',
    `Every pair of a foreground and a background the kit draws is listed here. A text pair must reach ${formatRatio(MINIMUM_CONTRAST.text)} and a pair of a user interface part or the focus ring ${formatRatio(MINIMUM_CONTRAST.ui)} (WCAG 2.2, 1.4.3 and 1.4.11), in both modes. \`npm run test:kit -- tokens\` fails when one does not, and so does \`composer check\`.`,
    '',
    '| Foreground | Background | Kind | Minimum | Light | Dark |',
    '|---|---|---|---|---|---|',
    ...pairs,
    '',
    '## Part hooks',
    '',
    'A part hook is a `data-cms-part` attribute on a curated element of the kit. The markup, the class names and every other attribute of a component are internal; only a theme, in the cascade layer `cms.theme`, may target a part hook, and only to set tokens for that part. Every hook is experimental.',
    '',
    '| Part | Stability | Since | Element |',
    '|---|---|---|---|',
    ...parts,
    '',
  ].join('\n');
}
