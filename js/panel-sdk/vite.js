// @cboxdk/cms-panel/vite: the Vite plugin an addon builds its panel bundle with (section 4.5 of
// the panel extension architecture).
//
//     import cmsPanelAddon from '@cboxdk/cms-panel/vite';
//     export default defineConfig({
//       plugins: [cmsPanelAddon({ namespace: 'approvals', contributions: ['approvals.badge'] })],
//       build: { lib: { entry: 'src/panel.ts', formats: ['es'] } },
//     });
//
// It leaves the panel's shared modules to the panel's import map: React, its JSX runtime, React
// DOM and the SDK with its subpaths are external, so the bundle runs on the panel's own React and
// kit and never on a second copy, and the CommonJS shim of useSyncExternalStore that React Aria
// requires is pointed at React's own hook. It fails the build on an import an addon may not make:
// Inertia, which the panel's import map refuses an addon at run time too, React Aria and the
// component kit's own package, whose components an addon reaches only through @cboxdk/cms-panel,
// and a module loaded from a URL, which the panel's content security policy would refuse.
//
// On the built files it fails on eval, new Function, a string as a timer and a dynamic import of
// another origin; on a stylesheet with a rule outside the addon's cascade layer, cms.addon.<ns>,
// with !important, an @import, a --cms-* declaration or a selector on the panel's own classes or
// parts; and on a bundle over its size budget. It scopes every selector of the stylesheets to the
// addon's own subtree, [data-cms-addon="<ns>"], which the panel's host renders each contribution
// inside. Last it writes panel-manifest.json next to the files: the entry, every file with its
// SHA-384 and kind, the shared modules the bundle imports and the contribution ids it registers
// code for, which cms:build checks against the addon's manifest (panel-bundle.v1.json). Given the
// publisher's Ed25519 private key (`sign`), it also writes panel-signature.json, the signature over
// the manifest's bytes with the publisher's public key (panel-bundle-signature.v1.json), which
// cms:build verifies against the keys the installation trusts for the addon (PRD 13.8); without
// one it warns, because an installation accepts an unsigned bundle in its local environment alone.
//
// In the dev server (`vite`) the entry module is served at DEV_ENTRY, which the panel's import map
// names when CBOX_CMS_PANEL_DEV_ADDONS points the addon at the server, so the panel loads the
// addon from the server with hot module replacement. The server's import analysis writes an
// import of a shared module as `/@id/<specifier>` on the server's origin, and the panel's import
// map maps each of those to the panel's own copy too (DEV_SHARED_PREFIX).

import { Buffer } from 'node:buffer';
import { createHash, createPrivateKey, createPublicKey, sign } from 'node:crypto';
import { isAbsolute, resolve } from 'node:path';

/**
 * The bare specifiers of the panel's shared React modules, as js/panel/shared-modules.json lists
 * them and the panel's import map maps them; a test holds the two equal.
 *
 * @stable
 */
export const SHARED_MODULES = Object.freeze([
  'react',
  'react/jsx-runtime',
  'react-dom',
  'react-dom/client',
]);

/**
 * The SDK's package, which the import map maps with every subpath to the panel's own copy.
 *
 * @stable
 */
export const SDK = '@cboxdk/cms-panel';

/**
 * The cascade layer every rule of an addon's stylesheet sits in, `cms.addon.<namespace>` or a
 * layer below it, as Cbox\Cms\Core\Registry\Domain\AddonLayer::LAYER has the parent.
 *
 * @stable
 */
export const ADDON_LAYER = 'cms.addon';

/**
 * The attribute every selector of an addon's stylesheet is scoped to, which the host puts on the
 * element each contribution renders inside.
 *
 * @stable
 */
export const SCOPE_ATTRIBUTE = 'data-cms-addon';

/**
 * The path the dev server serves the addon's entry module at.
 *
 * @stable
 */
export const DEV_ENTRY = '/@cms-panel-addon/entry';

/**
 * The path the dev server's import analysis gives an import of a shared module, before the bare
 * specifier: the panel's import map maps `<origin>/@id/<specifier>` for each dev server.
 *
 * @stable
 */
export const DEV_SHARED_PREFIX = '/@id/';

/**
 * The file the plugin writes next to the built files.
 *
 * @stable
 */
export const MANIFEST = 'panel-manifest.json';

/**
 * The file the plugin writes the publisher's signature over the manifest's bytes to, next to the
 * manifest, when it is given the publisher's key.
 *
 * @stable
 */
export const SIGNATURE = 'panel-signature.json';

/**
 * The signature algorithm, as panel-signature.json names it: Ed25519 (RFC 8032).
 *
 * @stable
 */
export const SIGNATURE_ALGORITHM = 'ed25519';

/**
 * @typedef {object} BundleSignature
 * @property {'ed25519'} algorithm The signature algorithm.
 * @property {string} public_key The publisher's public key, the base64 of its 32 bytes, which the installation names in cbox-cms.addons.publishers.
 * @property {string} signature The signature over the bytes of panel-manifest.json, the base64 of its 64 bytes.
 * @stable
 */

/**
 * The publisher's Ed25519 private key, read from its PEM (PKCS#8, as `openssl genpkey -algorithm
 * ed25519` writes it).
 *
 * @param {string} pem
 * @returns {import('node:crypto').KeyObject}
 * @throws {TypeError} when the PEM is not an Ed25519 private key
 */
function privateKeyOf(pem) {
  /** @type {import('node:crypto').KeyObject} */
  let key;

  try {
    key = createPrivateKey(pem);
  } catch (error) {
    throw new TypeError(
      `sign.privateKey is not a private key in PEM: ${error instanceof Error ? error.message : String(error)}`,
      { cause: error },
    );
  }

  if (key.asymmetricKeyType !== SIGNATURE_ALGORITHM) {
    throw new TypeError(
      `sign.privateKey is a ${String(key.asymmetricKeyType)} key; the panel verifies Ed25519 signatures. Make one with: openssl genpkey -algorithm ed25519 -out panel-signing.pem`,
    );
  }

  return key;
}

/**
 * The public key of an Ed25519 private key in PEM, the base64 of its 32 bytes: what the
 * installation puts in cbox-cms.addons.publishers for the addon.
 *
 * @param {string} pem
 * @returns {string}
 * @stable
 */
export function publicKeyOf(pem) {
  const jwk = createPublicKey(privateKeyOf(pem)).export({ format: 'jwk' });

  if (typeof jwk.x !== 'string') {
    throw new TypeError('The key exports no public key.');
  }

  return Buffer.from(jwk.x, 'base64url').toString('base64');
}

/**
 * The publisher's signature over the manifest's bytes by the Ed25519 private key in PEM: the
 * document of panel-signature.json.
 *
 * @param {Uint8Array | string} manifest the bytes of panel-manifest.json
 * @param {string} pem
 * @returns {BundleSignature}
 * @stable
 */
export function signManifest(manifest, pem) {
  const bytes = typeof manifest === 'string' ? Buffer.from(manifest, 'utf8') : manifest;

  return {
    algorithm: SIGNATURE_ALGORITHM,
    public_key: publicKeyOf(pem),
    signature: sign(null, bytes, privateKeyOf(pem)).toString('base64'),
  };
}

/**
 * The size budget of a bundle unless the addon names another: every built file together, in
 * bytes before compression.
 *
 * @stable
 */
export const DEFAULT_BUDGET = 256 * 1024;

/** The form of an addon's namespace, AddonNamespace::PATTERN, less the reserved app and ext. */
const NAMESPACE = /^[a-z][a-z0-9]{0,19}$/;

/** The form of a contribution id, ContributionId::PATTERN. */
const CONTRIBUTION_ID = /^[a-z][a-z0-9]{0,19}(?:\.[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*)+$/;

/** The CommonJS shim React Aria requires, which the bundle points at React's own hook. */
const SYNC_STORE_SHIM = /^use-sync-external-store\/shim(?:\/index\.js)?$/;

const SYNC_STORE_MODULE = '\0cms-panel-addon:use-sync-external-store';

/**
 * Each import an addon may not make, with why.
 *
 * @type {readonly { matches: (specifier: string) => boolean, reason: string }[]}
 */
const REFUSED = Object.freeze([
  {
    matches: (specifier) => specifier.startsWith('@inertiajs/'),
    reason:
      "Inertia is the panel's own: a contribution reaches the panel only through usePanelHost(), and the panel's import map refuses Inertia to an addon at run time too.",
  },
  {
    matches: (specifier) =>
      /^(?:react-aria-components|react-aria|react-stately)(?:\/|$)/.test(specifier) ||
      /^@(?:react-aria|react-stately|react-types)\//.test(specifier),
    reason:
      "React Aria is internal to the panel's component kit (decision D2): use the kit's components from @cboxdk/cms-panel/ui or /experimental.",
  },
  {
    matches: (specifier) =>
      specifier === '@cboxdk/cms-ui-kit' || specifier.startsWith('@cboxdk/cms-ui-kit/'),
    reason:
      'The component kit is private to the panel (decision D1): import its components from @cboxdk/cms-panel/ui or /experimental.',
  },
  {
    matches: (specifier) =>
      /^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(specifier) || /^(?:https?|data|blob):/i.test(specifier),
    reason:
      "A module is loaded only from the addon's own bundle: the panel's content security policy refuses every other origin.",
  },
]);

/**
 * What the built code may not do, each with what the panel's policy or review refuses.
 *
 * @type {readonly { pattern: RegExp, what: string }[]}
 */
const CODE_LINT = Object.freeze([
  { pattern: /(?<![\w$.])eval\s*\(/, what: 'calls eval()' },
  { pattern: /\bnew\s+Function\s*\(/, what: 'calls new Function()' },
  {
    pattern: /(?<![\w$.])set(?:Timeout|Interval)\s*\(\s*["'`]/,
    what: 'passes a string to a timer',
  },
  {
    pattern: /\bimport\s*\((?:\s|\/\*[\s\S]*?\*\/)*["'`](?:[a-z][a-z0-9+.-]*:)?\/\//i,
    what: 'imports a module from another origin',
  },
]);

/**
 * Whether the import is one of the panel's shared modules, which the bundle leaves to the import
 * map.
 *
 * @param {string} specifier
 * @returns {boolean}
 * @stable
 */
export function isShared(specifier) {
  return SHARED_MODULES.includes(specifier) || specifier === SDK || specifier.startsWith(`${SDK}/`);
}

/**
 * Why an addon may not import the module, or null when it may.
 *
 * @param {string} specifier
 * @returns {string | null}
 * @stable
 */
export function refusal(specifier) {
  return REFUSED.find((refused) => refused.matches(specifier))?.reason ?? null;
}

/**
 * Why the built code may not ship, or null when nothing in it is refused.
 *
 * @param {string} code
 * @returns {string | null}
 * @stable
 */
export function codeRefusal(code) {
  const hit = CODE_LINT.find((lint) => lint.pattern.test(code));

  return hit === undefined
    ? null
    : `${hit.what}, which the panel's content security policy refuses and review does not accept`;
}

/**
 * The SHA-384 of the bytes in the form of Subresource Integrity, as panel-bundle.v1.json and the
 * panel's import map carry it.
 *
 * @param {Uint8Array | string} bytes
 * @returns {string}
 * @stable
 */
export function integrity(bytes) {
  return `sha384-${createHash('sha384').update(bytes).digest('base64')}`;
}

/**
 * A stylesheet without its comments, each replaced by a space.
 *
 * @param {string} css
 * @returns {string}
 */
function withoutComments(css) {
  return css.replace(/\/\*[\s\S]*?(?:\*\/|$)/g, ' ');
}

/**
 * The offset of the brace that closes the block opened just before the offset, past strings and
 * nested blocks, or -1 when it is never closed.
 *
 * @param {string} css
 * @param {number} at
 * @returns {number}
 */
function blockEnd(css, at) {
  let depth = 1;
  /** @type {string | null} */
  let quote = null;

  for (let index = at; index < css.length; index++) {
    const char = css[index];

    if (quote !== null) {
      if (char === '\\') {
        index++;
      } else if (char === quote) {
        quote = null;
      }

      continue;
    }

    if (char === '"' || char === "'") {
      quote = char;
    } else if (char === '{') {
      depth++;
    } else if (char === '}' && --depth === 0) {
      return index;
    }
  }

  return -1;
}

/**
 * Whether every name of a layer list is the addon's layer or below it.
 *
 * @param {string} names
 * @param {string} layer
 * @returns {boolean}
 */
function addonLayers(names, layer) {
  return names
    .split(',')
    .map((name) => name.trim())
    .every((name) => name === layer || name.startsWith(`${layer}.`));
}

/**
 * The start of the first text of the stylesheet outside the addon's layer, at most 60
 * characters, or null when every rule is inside it: after comments, a stylesheet is at most one
 * @charset and then only @layer statements and blocks of the addon's layer, as
 * AddonLayer::firstUnlayered() reads it in cms:build.
 *
 * @param {string} css
 * @param {string} namespace
 * @returns {string | null}
 * @stable
 */
export function firstUnlayered(css, namespace) {
  const layer = `${ADDON_LAYER}.${namespace}`;
  const text = withoutComments(css);
  let at = 0;
  const charset = /^\s*@charset\s+"[^"]*"\s*;/.exec(text);

  if (charset !== null) {
    at = charset[0].length;
  }

  for (;;) {
    while (at < text.length && /\s/.test(text[at] ?? '')) {
      at++;
    }

    if (at >= text.length) {
      return null;
    }

    const statement = /^@layer\s+([^{;]+?)\s*([{;])/.exec(text.slice(at));

    if (statement === null || !addonLayers(statement[1] ?? '', layer)) {
      return text
        .slice(at, at + 60)
        .replace(/\s+/g, ' ')
        .trim();
    }

    at += statement[0].length;

    if (statement[2] === ';') {
      continue;
    }

    const end = blockEnd(text, at);

    if (end === -1) {
      return text
        .slice(at, at + 60)
        .replace(/\s+/g, ' ')
        .trim();
    }

    at = end + 1;
  }
}

/**
 * The selectors of a prelude, split at the commas outside parentheses and strings.
 *
 * @param {string} prelude
 * @returns {string[]}
 */
function selectorsOf(prelude) {
  const selectors = [];
  let depth = 0;
  /** @type {string | null} */
  let quote = null;
  let start = 0;

  for (let index = 0; index < prelude.length; index++) {
    const char = prelude[index];

    if (quote !== null) {
      if (char === '\\') {
        index++;
      } else if (char === quote) {
        quote = null;
      }
    } else if (char === '"' || char === "'") {
      quote = char;
    } else if (char === '(' || char === '[') {
      depth++;
    } else if (char === ')' || char === ']') {
      depth--;
    } else if (char === ',' && depth === 0) {
      selectors.push(prelude.slice(start, index));
      start = index + 1;
    }
  }

  selectors.push(prelude.slice(start));

  return selectors;
}

/** The at-rules whose blocks hold rules, which are scoped inside them. */
const CONDITIONAL_AT_RULES = /^@(?:media|supports|container|layer|scope|starting-style)\b/;

/**
 * The rules of a block, each selector prefixed with the addon's scope, inside conditional at-rules
 * too; every other at-rule, such as @keyframes and @font-face, stays as it is. A declaration that
 * sits directly in a nested block, such as `color: red` inside a rule, stays as it is too.
 *
 * @param {string} css
 * @param {string} scope
 * @returns {string}
 */
function scopeRules(css, scope) {
  let out = '';
  let at = 0;

  while (at < css.length) {
    const rest = css.slice(at);
    const next = /^\s*/.exec(rest)?.[0].length ?? 0;
    out += rest.slice(0, next);
    at += next;

    if (at >= css.length) {
      break;
    }

    let index = at;
    /** @type {string | null} */
    let quote = null;
    let depth = 0;

    for (; index < css.length; index++) {
      const char = css[index];

      if (quote !== null) {
        if (char === '\\') {
          index++;
        } else if (char === quote) {
          quote = null;
        }
      } else if (char === '"' || char === "'") {
        quote = char;
      } else if (char === '(' || char === '[') {
        depth++;
      } else if (char === ')' || char === ']') {
        depth--;
      } else if (depth === 0 && (char === '{' || char === ';')) {
        break;
      }
    }

    const prelude = css.slice(at, index);

    if (index >= css.length || css[index] === ';') {
      out += css.slice(at, index + 1);
      at = index + 1;

      continue;
    }

    const end = blockEnd(css, index + 1);
    const body = end === -1 ? css.slice(index + 1) : css.slice(index + 1, end);
    const trimmed = prelude.trim();

    if (trimmed.startsWith('@')) {
      out += `${prelude}{${CONDITIONAL_AT_RULES.test(trimmed) ? scopeRules(body, scope) : body}}`;
    } else if (trimmed === '' || /^[a-z-]+\s*:/i.test(trimmed)) {
      out += `${prelude}{${body}}`;
    } else {
      const scoped = selectorsOf(prelude)
        .map((selector) => `${scope} ${selector.trim()}`)
        .join(', ');
      out += `${scoped}{${body}}`;
    }

    at = end === -1 ? css.length : end + 1;
  }

  return out;
}

/**
 * The stylesheet with every selector inside the addon's layer scoped to the addon's subtree.
 *
 * @param {string} css
 * @param {string} namespace
 * @returns {string}
 * @stable
 */
export function scopeStylesheet(css, namespace) {
  return scopeRules(css, `[${SCOPE_ATTRIBUTE}="${namespace}"]`);
}

/**
 * Why the stylesheet may not ship, or null when it may: a rule outside the addon's layer,
 * !important, an import rule, a --cms-* declaration, or a selector on the panel's own classes or
 * parts.
 *
 * @param {string} css
 * @param {string} namespace
 * @returns {string | null}
 * @stable
 */
export function styleRefusal(css, namespace) {
  const text = withoutComments(css);
  const unlayered = firstUnlayered(css, namespace);

  if (unlayered !== null) {
    return `has a rule outside the cascade layer ${ADDON_LAYER}.${namespace}, at "${unlayered}"; keep every rule in @layer ${ADDON_LAYER}.${namespace}`;
  }

  if (/!\s*important\b/i.test(text)) {
    return "uses !important, which would override the panel's own layers";
  }

  if (/@import\b/i.test(text)) {
    return "has an @import; a stylesheet is loaded only from the addon's own bundle";
  }

  if (/(?:^|[\s;{])--cms-[\w-]*\s*:/.test(text)) {
    return "sets a --cms-* token, which only the installation's theme may set";
  }

  if (/\.cms-[\w-]*|\[\s*data-cms-part\b/.test(text)) {
    return "targets the panel's own classes or data-cms-part hooks, which only the installation's theme may style";
  }

  return null;
}

/**
 * The entry a Vite configuration builds, or null when it names none or several.
 *
 * @param {import('vite').ResolvedConfig} config
 * @returns {string | null}
 */
function entryOf(config) {
  const lib = config.build.lib;

  if (lib !== false && lib.entry !== undefined) {
    const entries = Array.isArray(lib.entry)
      ? lib.entry
      : typeof lib.entry === 'string'
        ? [lib.entry]
        : Object.values(lib.entry);

    return entries.length === 1 && entries[0] !== undefined ? entries[0] : null;
  }

  const input = config.build.rolldownOptions.input;

  if (typeof input === 'string') {
    return input;
  }

  if (Array.isArray(input)) {
    return input.length === 1 ? (input[0] ?? null) : null;
  }

  if (input !== undefined) {
    const entries = Object.values(input);

    return entries.length === 1 ? (entries[0] ?? null) : null;
  }

  return null;
}

/**
 * @typedef {object} SigningOptions
 * @property {string} privateKey The publisher's Ed25519 private key in PEM (PKCS#8), read by the configuration from a file or a secret outside the repository, never committed.
 * @stable
 */

/**
 * @typedef {object} PanelAddonOptions
 * @property {string} namespace The addon's namespace, as its manifest declares it.
 * @property {readonly string[]} contributions The ids of the contributions the bundle registers code for: exactly the addon's contributions that run code.
 * @property {number} [budget] The bundle's size budget in bytes, every built file together; DEFAULT_BUDGET unless given.
 * @property {SigningOptions} [sign] The publisher's key, which signs the manifest into panel-signature.json; without it the bundle is unsigned, which only a local installation accepts.
 * @stable
 */

/**
 * The plugins: one before Vite's own, which marks the shared modules external, refuses the
 * imports an addon may not make and serves the entry in the dev server, and one after them, which
 * checks and scopes the built files and writes the manifest, because Vite emits the stylesheets
 * after the first ran.
 *
 * @param {PanelAddonOptions} options
 * @returns {import('vite').Plugin[]}
 * @stable
 */
export default function cmsPanelAddon(options) {
  const { namespace, contributions, budget = DEFAULT_BUDGET, sign: signing } = options;

  if (
    typeof namespace !== 'string' ||
    !NAMESPACE.test(namespace) ||
    namespace === 'app' ||
    namespace === 'ext'
  ) {
    throw new TypeError(
      `The addon namespace ${JSON.stringify(namespace)} is not a lower-case word of at most 20 letters and digits, other than app and ext.`,
    );
  }

  if (!Array.isArray(contributions)) {
    throw new TypeError(
      'contributions is the list of the ids of the contributions the bundle registers code for.',
    );
  }

  const ids = [...new Set(contributions)].sort();

  for (const id of ids) {
    if (typeof id !== 'string' || !CONTRIBUTION_ID.test(id) || !id.startsWith(`${namespace}.`)) {
      throw new TypeError(
        `The contribution id ${JSON.stringify(id)} is not "${namespace}." followed by dotted names, such as "${namespace}.badge".`,
      );
    }
  }

  if (!Number.isInteger(budget) || budget < 1) {
    throw new TypeError("budget is the bundle's size budget in bytes, a positive integer.");
  }

  if (signing !== undefined) {
    // Read as unknown: at run time the configuration can hold anything.
    const given = /** @type {unknown} */ (signing);

    if (
      typeof given !== 'object' ||
      given === null ||
      !('privateKey' in given) ||
      typeof given.privateKey !== 'string'
    ) {
      throw new TypeError(
        "sign is { privateKey } with the publisher's Ed25519 private key in PEM, or left out.",
      );
    }

    privateKeyOf(signing.privateKey);
  }

  /** @type {import('vite').ResolvedConfig | null} */
  let resolved = null;

  /** @type {import('vite').Plugin} */
  const resolver = {
    name: 'cboxdk-cms-panel-addon',
    enforce: 'pre',
    config() {
      return {
        build: {
          manifest: false,
          modulePreload: false,
          rolldownOptions: {
            preserveEntrySignatures: 'strict',
            output: { format: 'es' },
          },
        },
      };
    },
    configResolved(config) {
      resolved = config;
      const output = config.build.rolldownOptions.output;
      const outputs = Array.isArray(output) ? output : output === undefined ? [] : [output];

      for (const options of outputs) {
        if (options.format !== undefined && options.format !== 'es' && options.format !== 'esm') {
          throw new Error(
            `The panel loads an addon's bundle as ES modules; the build's output format is ${options.format}.`,
          );
        }
      }

      const lib = config.build.lib;

      if (
        lib !== false &&
        lib.formats !== undefined &&
        lib.formats.some((format) => format !== 'es')
      ) {
        throw new Error(
          `The panel loads an addon's bundle as ES modules; the build's lib formats are ${lib.formats.join(', ')}.`,
        );
      }
    },
    async resolveId(source, importer) {
      const reason = refusal(source);

      if (reason !== null) {
        this.error(
          `${importer === undefined ? 'The bundle' : importer} imports ${source}, which an addon of the Cbox CMS panel may not import. ${reason}`,
        );
      }

      if (isShared(source)) {
        return { id: source, external: true };
      }

      if (SYNC_STORE_SHIM.test(source)) {
        return SYNC_STORE_MODULE;
      }

      if (source === DEV_ENTRY && resolved !== null) {
        const entry = entryOf(resolved);

        if (entry === null) {
          this.error(
            `The dev server serves the addon's entry at ${DEV_ENTRY}, and the build names no single entry: set build.lib.entry or build.rolldownOptions.input to it.`,
          );
        }

        const file = isAbsolute(entry) ? entry : resolve(resolved.root, entry);
        const found = await this.resolve(file, undefined, { skipSelf: true });

        return found ?? file;
      }

      return null;
    },
    load(id) {
      return id === SYNC_STORE_MODULE ? "export { useSyncExternalStore } from 'react';\n" : null;
    },
  };

  /** @type {import('vite').Plugin} */
  const bundler = {
    name: 'cboxdk-cms-panel-addon:bundle',
    enforce: 'post',
    generateBundle(_outputOptions, bundle) {
      const files = [];
      const externals = new Set();
      /** @type {string | null} */
      let entry = null;
      let size = 0;

      for (const [fileName, output] of Object.entries(bundle)) {
        if (output.type === 'chunk') {
          const refused = codeRefusal(output.code);

          if (refused !== null) {
            this.error(`The built file ${fileName} ${refused}.`);
          }

          for (const imported of [...output.imports, ...output.dynamicImports]) {
            if (!Object.hasOwn(bundle, imported)) {
              if (!isShared(imported)) {
                this.error(
                  `The built file ${fileName} imports ${imported}, which is not a module the panel shares.`,
                );
              }

              externals.add(imported);
            }
          }

          if (output.isEntry) {
            if (entry !== null) {
              this.error(
                `The bundle has the entries ${entry} and ${fileName}; an addon's bundle has one entry module.`,
              );
            }

            entry = fileName;
          }

          const bytes = Buffer.from(output.code, 'utf8');
          size += bytes.length;
          files.push({ integrity: integrity(bytes), kind: 'script', path: fileName });

          continue;
        }

        if (fileName.endsWith('.css')) {
          const css =
            typeof output.source === 'string'
              ? output.source
              : Buffer.from(output.source).toString('utf8');
          const refused = styleRefusal(css, namespace);

          if (refused !== null) {
            this.error(`The stylesheet ${fileName} ${refused}.`);
          }

          const scoped = scopeStylesheet(css, namespace);
          output.source = scoped;
          const bytes = Buffer.from(scoped, 'utf8');
          size += bytes.length;
          files.push({ integrity: integrity(bytes), kind: 'style', path: fileName });

          continue;
        }

        const bytes =
          typeof output.source === 'string' ? Buffer.from(output.source, 'utf8') : output.source;
        size += bytes.length;
        files.push({ integrity: integrity(bytes), kind: 'asset', path: fileName });
      }

      if (entry === null) {
        this.error('The bundle has no entry module.');
      }

      if (size > budget) {
        this.error(
          `The bundle is ${String(size)} bytes, over its budget of ${String(budget)} bytes. Split what a page does not need into a dynamic import, or raise the budget in cmsPanelAddon({ budget }) with a reason.`,
        );
      }

      files.sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));

      const manifest = {
        contributions: ids,
        entry,
        externals: [...externals].sort(),
        files,
      };

      const document = `${JSON.stringify(manifest, null, 2)}\n`;

      this.emitFile({ type: 'asset', fileName: MANIFEST, source: document });

      if (signing === undefined) {
        this.warn(
          `The bundle is not signed: an installation accepts it in its local environment alone (PRD 13.8). Give cmsPanelAddon({ sign: { privateKey } }) the publisher's Ed25519 private key to write ${SIGNATURE}.`,
        );

        return;
      }

      this.emitFile({
        type: 'asset',
        fileName: SIGNATURE,
        source: `${JSON.stringify(signManifest(document, signing.privateKey), null, 2)}\n`,
      });
    },
  };

  return [resolver, bundler];
}
