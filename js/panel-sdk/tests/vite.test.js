// The addon build plugin, @cboxdk/cms-panel/vite: a build leaves the panel's shared modules
// external, so the bundle runs on the panel's React and SDK, fails on an import an addon may not
// make, naming it and why, on built code that calls eval, new Function or a string timer, on a
// stylesheet outside the addon's layer or with !important, and on a bundle over its budget; it
// scopes the stylesheets to the addon's subtree and writes panel-manifest.json with the SHA-384 of
// every file. Its list of shared modules is the panel's. In the dev server the entry is served at
// DEV_ENTRY with the shared modules still bare.

import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { createHash, generateKeyPairSync, verify } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { build, createServer } from 'vite';
import { afterAll, describe, test } from 'vitest';

import cmsPanelAddon, {
  codeRefusal,
  DEV_ENTRY,
  DEV_SHARED_PREFIX,
  firstUnlayered,
  integrity,
  isShared,
  MANIFEST,
  publicKeyOf,
  refusal,
  scopeStylesheet,
  SDK,
  SHARED_MODULES,
  SIGNATURE,
  signManifest,
  styleRefusal,
} from '../vite.js';
import { ROOT } from './sdk.js';

const WORK = join(ROOT, '.cache', `panel-sdk-vite-test-${String(process.pid)}`);

const NAMESPACE = 'acme';

afterAll(() => {
  rmSync(WORK, { recursive: true, force: true });
});

/**
 * @typedef {object} BundleFile
 * @property {string} integrity
 * @property {string} kind
 * @property {string} path
 */

/**
 * @typedef {object} Manifest
 * @property {string[]} contributions
 * @property {string} entry
 * @property {string[]} externals
 * @property {BundleFile[]} files
 */

/**
 * @typedef {object} Built
 * @property {string} code every built script, joined
 * @property {Record<string, string>} files every built file by name
 * @property {Manifest} manifest the manifest the plugin wrote
 */

/**
 * The manifest the plugin wrote, read as its form.
 *
 * @param {string} json
 * @returns {Manifest}
 */
function parseManifest(json) {
  /** @type {unknown} */
  const parsed = JSON.parse(json);

  return /** @type {Manifest} */ (parsed);
}

/**
 * Builds an addon whose entry is the source, with the other files beside it, and gives its output
 * or the build's error.
 *
 * @param {string} name
 * @param {string} source
 * @param {{ files?: Record<string, string>, contributions?: string[], budget?: number, sign?: { privateKey: string } }} [options]
 * @returns {Promise<Built | { error: string }>}
 */
async function buildAddon(name, source, options = {}) {
  const directory = join(WORK, name);

  mkdirSync(directory, { recursive: true });
  writeFileSync(join(directory, 'entry.js'), source);

  for (const [file, contents] of Object.entries(options.files ?? {})) {
    writeFileSync(join(directory, file), contents);
  }

  try {
    await build({
      configFile: false,
      root: directory,
      logLevel: 'silent',
      plugins: [
        cmsPanelAddon({
          namespace: NAMESPACE,
          contributions: options.contributions ?? [`${NAMESPACE}.badge`],
          ...(options.budget === undefined ? {} : { budget: options.budget }),
          ...(options.sign === undefined ? {} : { sign: options.sign }),
        }),
      ],
      build: {
        outDir: join(directory, 'dist'),
        emptyOutDir: true,
        minify: false,
        lib: { entry: join(directory, 'entry.js'), formats: ['es'], fileName: 'entry' },
      },
    });
  } catch (error) {
    return { error: String(error instanceof Error ? error.message : error) };
  }

  /** @type {Record<string, string>} */
  const files = {};

  for (const file of readdirSync(join(directory, 'dist'), { recursive: true })) {
    const path = String(file);

    try {
      files[path] = readFileSync(join(directory, 'dist', path), 'utf8');
    } catch {
      // A directory.
    }
  }

  const manifestJson = files[MANIFEST];

  assert.ok(manifestJson !== undefined, `the plugin wrote no ${MANIFEST}`);

  return {
    code: Object.entries(files)
      .filter(([file]) => file.endsWith('.js'))
      .map(([, contents]) => contents)
      .join('\n'),
    files,
    manifest: parseManifest(manifestJson),
  };
}

const SHARED_SOURCE = [
  "import { useState } from 'react';",
  "import { jsx } from 'react/jsx-runtime';",
  "import { usePanelHost } from '@cboxdk/cms-panel/extend';",
  'export default function Badge() { const [count] = useState(1); return jsx("span", { children: usePanelHost().t("a.b", { count }) }); }',
  '',
].join('\n');

const LAYERED_CSS = [
  `@layer cms.addon.${NAMESPACE} {`,
  '  .badge, .badge:hover > em { color: red; }',
  '  @media (width > 40em) { .badge { padding: 1rem; } }',
  '  @keyframes pulse { from { opacity: 0; } to { opacity: 1; } }',
  '}',
  '',
].join('\n');

/**
 * A publisher's Ed25519 keypair: the private key in PEM, as the plugin takes it, and the public
 * key as the installation names it, the base64 of its 32 bytes.
 *
 * @returns {{ privateKey: string, publicKey: string, publicKeyObject: import('node:crypto').KeyObject }}
 */
function publisherKeypair() {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const jwk = publicKey.export({ format: 'jwk' });

  return {
    privateKey: String(privateKey.export({ type: 'pkcs8', format: 'pem' })),
    publicKey: Buffer.from(String(jwk.x), 'base64url').toString('base64'),
    publicKeyObject: publicKey,
  };
}

describe('the addon build plugin', () => {
  test(
    "signs the manifest with the publisher's key into panel-signature.json, which verifies over the manifest's bytes, and writes none without a key",
    { timeout: 60_000 },
    async () => {
      const publisher = publisherKeypair();
      const signed = await buildAddon('signed', SHARED_SOURCE, {
        sign: { privateKey: publisher.privateKey },
      });

      assert.ok('files' in signed, 'error' in signed ? signed.error : '');

      const document = signed.files[MANIFEST];
      const signatureJson = signed.files[SIGNATURE];

      assert.ok(
        document !== undefined && signatureJson !== undefined,
        `the plugin wrote no ${SIGNATURE}`,
      );

      /** @type {unknown} */
      const parsed = JSON.parse(signatureJson);
      const signature =
        /** @type {{ algorithm: string, public_key: string, signature: string }} */ (parsed);

      assert.equal(signature.algorithm, 'ed25519');
      assert.equal(signature.public_key, publisher.publicKey);
      assert.equal(publicKeyOf(publisher.privateKey), publisher.publicKey);
      assert.equal(Buffer.from(signature.public_key, 'base64').length, 32);
      assert.equal(Buffer.from(signature.signature, 'base64').length, 64);
      assert.ok(
        verify(
          null,
          Buffer.from(document, 'utf8'),
          publisher.publicKeyObject,
          Buffer.from(signature.signature, 'base64'),
        ),
        'the signature does not verify over the manifest',
      );
      assert.ok(
        !verify(
          null,
          Buffer.from(`${document} `, 'utf8'),
          publisher.publicKeyObject,
          Buffer.from(signature.signature, 'base64'),
        ),
        'the signature verifies over other bytes',
      );
      assert.deepEqual(signManifest(document, publisher.privateKey), signature);
      assert.ok(
        !Object.hasOwn(parseManifest(document), 'signature'),
        'the manifest carries the signature',
      );

      const unsigned = await buildAddon('unsigned', SHARED_SOURCE);

      assert.ok('files' in unsigned, 'error' in unsigned ? unsigned.error : '');
      assert.equal(unsigned.files[SIGNATURE], undefined);
    },
  );

  test('refuses a signing key that is not an Ed25519 private key', () => {
    const rsa = String(
      generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey.export({
        type: 'pkcs8',
        format: 'pem',
      }),
    );

    assert.throws(
      () => cmsPanelAddon({ namespace: NAMESPACE, contributions: [], sign: { privateKey: rsa } }),
      /sign\.privateKey is a rsa key; the panel verifies Ed25519 signatures/,
    );
    assert.throws(
      () =>
        cmsPanelAddon({
          namespace: NAMESPACE,
          contributions: [],
          sign: { privateKey: 'not a key' },
        }),
      /sign\.privateKey is not a private key in PEM/,
    );
    assert.throws(
      () =>
        cmsPanelAddon({
          namespace: NAMESPACE,
          contributions: [],
          sign: /** @type {{ privateKey: string }} */ (/** @type {unknown} */ ({ keyFile: 'x' })),
        }),
      /sign is \{ privateKey \} with the publisher's Ed25519 private key in PEM, or left out/,
    );
  });

  test(
    "leaves React and the SDK to the import map, and writes the manifest with every file's SHA-384",
    { timeout: 60_000 },
    async () => {
      const result = await buildAddon('shared', SHARED_SOURCE, {
        contributions: [`${NAMESPACE}.badge`, `${NAMESPACE}.aside`, `${NAMESPACE}.badge`],
      });

      assert.ok('code' in result, 'error' in result ? result.error : '');
      assert.match(result.code, /from "react"/);
      assert.match(result.code, /from "react\/jsx-runtime"/);
      assert.match(result.code, /from "@cboxdk\/cms-panel\/extend"/);
      assert.doesNotMatch(result.code, /useSyncExternalStore|__SECRET_INTERNALS|react\.production/);

      const { manifest } = result;

      assert.equal(manifest.entry, 'entry.js');
      assert.deepEqual(manifest.contributions, [`${NAMESPACE}.aside`, `${NAMESPACE}.badge`]);
      assert.deepEqual(manifest.externals, [
        '@cboxdk/cms-panel/extend',
        'react',
        'react/jsx-runtime',
      ]);
      assert.deepEqual(
        manifest.files.map((file) => [file.path, file.kind]),
        [['entry.js', 'script']],
      );
      assert.equal(
        manifest.files[0]?.integrity,
        `sha384-${createHash('sha384')
          .update(result.files['entry.js'] ?? '')
          .digest('base64')}`,
      );
      assert.equal(
        integrity('abc'),
        `sha384-${createHash('sha384').update('abc').digest('base64')}`,
      );
    },
  );

  test(
    "scopes a layered stylesheet to the addon's subtree and lists it as a style",
    { timeout: 60_000 },
    async () => {
      const result = await buildAddon('styled', "import './badge.css';\nexport default 1;\n", {
        files: { 'badge.css': LAYERED_CSS },
      });

      assert.ok('code' in result, 'error' in result ? result.error : '');

      const style = result.manifest.files.find((file) => file.kind === 'style');

      assert.ok(style !== undefined, 'the manifest lists no stylesheet');

      const css = result.files[style.path] ?? '';

      assert.match(css, /@layer cms\.addon\.acme\s*\{/);
      assert.match(
        css,
        /\[data-cms-addon="acme"\] \.badge\s*,\s*\[data-cms-addon="acme"\] \.badge:hover\s*>\s*em\s*\{/,
      );
      assert.match(css, /@media[^{]*\{\s*\[data-cms-addon="acme"\] \.badge\s*\{/);
      assert.match(css, /@keyframes pulse\s*\{\s*from\s*\{/);
      assert.doesNotMatch(css, /\[data-cms-addon="acme"\] from/);
      assert.equal(style.integrity, integrity(css));
    },
  );

  test.each([
    [
      "import { router } from '@inertiajs/react'; export default router;",
      '@inertiajs/react',
      /Inertia is the panel's own/,
    ],
    [
      "import { Button } from 'react-aria-components'; export default Button;",
      'react-aria-components',
      /React Aria is internal/,
    ],
    [
      "import { Button } from '@cboxdk/cms-ui-kit'; export default Button;",
      '@cboxdk/cms-ui-kit',
      /The component kit is private/,
    ],
    [
      "import x from 'https://cdn.example.test/x.js'; export default x;",
      'https://cdn.example.test/x.js',
      /content security policy/,
    ],
  ])('fails on an import of %s', { timeout: 60_000 }, async (source, specifier, reason) => {
    const result = await buildAddon(`refused-${specifier.replace(/[^a-z]/gi, '')}`, `${source}\n`);

    assert.ok('error' in result, 'the build passed');
    assert.ok(
      result.error.includes(
        `imports ${specifier}, which an addon of the Cbox CMS panel may not import`,
      ),
      result.error,
    );
    assert.match(result.error, reason);
  });

  test.each([
    ['eval', 'export default eval("1 + 1");', /calls eval\(\)/],
    ['new Function', 'export default new Function("return 1");', /calls new Function\(\)/],
    ['a string timer', 'export default setTimeout("run()", 1);', /passes a string to a timer/],
    [
      'a dynamic import of another origin',
      'export default () => import(/* @vite-ignore */ "https://cdn.example.test/x.js");',
      /imports a module from another origin/,
    ],
  ])('fails on built code with %s', { timeout: 60_000 }, async (name, source, reason) => {
    const result = await buildAddon(`code-${name.replace(/[^a-z]/gi, '')}`, `${source}\n`);

    assert.ok('error' in result, 'the build passed');
    assert.match(result.error, /The built file entry\.js/);
    assert.match(result.error, reason);
  });

  test.each([
    [
      'unlayered',
      '.badge { color: red; }\n',
      /has a rule outside the cascade layer cms\.addon\.acme, at "\.badge\s*\{\s*color:\s*red;?\s*\}"/,
    ],
    [
      'another layer',
      '@layer cms.panel { .badge { color: red; } }\n',
      /has a rule outside the cascade layer cms\.addon\.acme/,
    ],
    [
      '!important',
      `@layer cms.addon.${NAMESPACE} { .badge { color: red !important; } }\n`,
      /uses !important/,
    ],
    [
      'an @import',
      `@import url("https://fonts.example.test/x.css");\n@layer cms.addon.${NAMESPACE} { .badge { color: red; } }\n`,
      /outside the cascade layer|has an @import/,
    ],
    [
      'a --cms-* token',
      `@layer cms.addon.${NAMESPACE} { .badge { --cms-color-accent: red; } }\n`,
      /sets a --cms-\* token/,
    ],
    [
      "the panel's own class",
      `@layer cms.addon.${NAMESPACE} { .cms-dialog { display: none; } }\n`,
      /targets the panel's own classes/,
    ],
  ])('fails on a stylesheet with %s', { timeout: 60_000 }, async (name, css, reason) => {
    const result = await buildAddon(
      `css-${name.replace(/[^a-z]/gi, '')}`,
      "import './badge.css';\nexport default 1;\n",
      { files: { 'badge.css': css } },
    );

    assert.ok('error' in result, 'the build passed');
    assert.match(result.error, /The stylesheet /);
    assert.match(result.error, reason);
  });

  test('fails on a bundle over its budget, naming both sizes', { timeout: 60_000 }, async () => {
    const result = await buildAddon(
      'budget',
      `export default ${JSON.stringify('x'.repeat(2048))};\n`,
      { budget: 1024 },
    );

    assert.ok('error' in result, 'the build passed');
    assert.match(result.error, /The bundle is \d+ bytes, over its budget of 1024 bytes/);
  });

  test(
    "points the useSyncExternalStore shim at React's own hook",
    { timeout: 60_000 },
    async () => {
      const result = await buildAddon(
        'shim',
        "import { useSyncExternalStore } from 'use-sync-external-store/shim';\nexport default useSyncExternalStore;\n",
      );

      assert.ok('code' in result, 'error' in result ? result.error : '');
      assert.match(result.code, /import \{ useSyncExternalStore \} from "react"/);
      assert.deepEqual(result.manifest.externals, ['react']);
    },
  );

  test(
    'serves the entry at DEV_ENTRY in the dev server, with the shared modules below DEV_SHARED_PREFIX',
    { timeout: 60_000 },
    async () => {
      const directory = join(WORK, 'dev');

      mkdirSync(directory, { recursive: true });
      writeFileSync(join(directory, 'entry.js'), SHARED_SOURCE);

      const server = await createServer({
        configFile: false,
        root: directory,
        logLevel: 'silent',
        server: { middlewareMode: true, hmr: false },
        plugins: [cmsPanelAddon({ namespace: NAMESPACE, contributions: [] })],
        build: { lib: { entry: join(directory, 'entry.js'), formats: ['es'] } },
      });

      try {
        const transformed = await server.environments.client.transformRequest(DEV_ENTRY);

        assert.ok(transformed !== null, 'the dev server did not serve the entry');
        assert.match(transformed.code, /from ["']\/@id\/react["']/);
        assert.match(transformed.code, /from ["']\/@id\/@cboxdk\/cms-panel\/extend["']/);
        assert.equal(DEV_SHARED_PREFIX, '/@id/');
        assert.match(transformed.code, /usePanelHost/);
      } finally {
        await server.close();
      }
    },
  );

  test("refuses options that are not an addon's", () => {
    assert.throws(() => cmsPanelAddon({ namespace: 'App', contributions: [] }), /namespace/);
    assert.throws(() => cmsPanelAddon({ namespace: 'app', contributions: [] }), /namespace/);
    assert.throws(
      () => cmsPanelAddon({ namespace: NAMESPACE, contributions: ['other.badge'] }),
      /contribution id "other\.badge"/,
    );
    assert.throws(
      () => cmsPanelAddon({ namespace: NAMESPACE, contributions: [], budget: 0 }),
      /budget/,
    );
  });

  test('shares the modules the panel shares, and the SDK with every subpath', () => {
    /** @type {unknown} */
    const parsed = JSON.parse(readFileSync(join(ROOT, 'js/panel/shared-modules.json'), 'utf8'));
    const modules = /** @type {{ shared: Record<string, string>, sdk: Record<string, string> }} */ (
      parsed
    );
    const php = readFileSync(
      join(ROOT, 'packages/core/src/Registry/Domain/SharedExternals.php'),
      'utf8',
    );

    assert.deepEqual([...SHARED_MODULES].sort(), Object.keys(modules.shared).sort());
    assert.equal(/const string SDK = '([^']+)';/.exec(php)?.[1], SDK);
    assert.ok(Object.keys(modules.sdk).every((specifier) => isShared(specifier)));
    assert.ok(isShared('@cboxdk/cms-panel/extend') && isShared('react-dom/client'));
    assert.ok(!isShared('@cboxdk/cms-panel-app') && !isShared('lodash'));
    assert.equal(refusal('lodash'), null);
    assert.equal(refusal('./Badge'), null);
    assert.notEqual(refusal('//cdn.example.test/x.js'), null);
    assert.notEqual(refusal('@react-aria/focus'), null);
  });

  test('reads a stylesheet as cms:build reads it', () => {
    assert.equal(firstUnlayered(LAYERED_CSS, NAMESPACE), null);
    assert.equal(
      firstUnlayered('/* a */ @charset "utf-8"; @layer cms.addon.acme.badge;', NAMESPACE),
      null,
    );
    assert.equal(
      firstUnlayered('@layer cms.addon { .a {} }', NAMESPACE),
      '@layer cms.addon { .a {} }',
    );
    assert.equal(
      firstUnlayered('@layer cms.addon.other { .a {} }', NAMESPACE),
      '@layer cms.addon.other { .a {} }',
    );
    assert.equal(styleRefusal(LAYERED_CSS, NAMESPACE), null);
    assert.match(
      styleRefusal('@layer cms.addon.acme { [data-cms-part=dialog] {} }', NAMESPACE) ?? '',
      /data-cms-part/,
    );
    assert.equal(
      scopeStylesheet(
        '@layer cms.addon.acme{.a,b[x=","]{color:red}@font-face{font-family:x}}',
        NAMESPACE,
      ),
      '@layer cms.addon.acme{[data-cms-addon="acme"] .a, [data-cms-addon="acme"] b[x=","]{color:red}@font-face{font-family:x}}',
    );
    assert.equal(codeRefusal('const evaluate = (x) => x; window.eval2(1); obj.eval(1);'), null);
    assert.match(codeRefusal('setInterval("tick()", 10)') ?? '', /string to a timer/);
  });
});
