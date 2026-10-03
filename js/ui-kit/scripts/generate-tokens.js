// Writes the files derived from js/ui-kit/tokens.json (GUARDRAILS 2.4: generated from one
// source): js/ui-kit/src/tokens.css, js/ui-kit/src/generated/tokens.ts, the JSON Schema of a theme
// in js/ui-kit/src/generated/theme.v1.json and docs/ui/tokens.md. The CSS, the TypeScript and the
// JSON are formatted with the repository's Prettier configuration, so gate 1 accepts them as they
// are. A file is written only when its bytes differ, so a second run changes nothing, and gate 6
// (`composer check:generated`) runs it and fails when the committed files differ.
//
// Run it as `npm run generate:tokens`, or `node js/ui-kit/scripts/generate-tokens.js
// [--root=<dir>]` to read and write another tree. It exits 65 when the catalogue has a problem,
// printing each, and writes nothing then; a contrast pair below its minimum is not a problem of
// the catalogue's form, so `npm run test:kit -- tokens` reports it instead.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import prettierConfig from '@cboxdk/cms-tooling/prettier';
import { format } from 'prettier';

import {
  readCatalogue,
  renderCss,
  renderDocs,
  renderThemeSchema,
  renderTypeScript,
} from './tokens.js';

/** The catalogue, relative to the root of the repository. */
export const SOURCE = 'js/ui-kit/tokens.json';

/**
 * The files the generator writes, relative to the root, each with how it is rendered and whether
 * Prettier formats it (Prettier leaves Markdown alone in this repository).
 *
 * @type {ReadonlyArray<{ path: string, render: (catalogue: import('./tokens.js').Catalogue) => string, prettier: boolean }>}
 */
export const OUTPUTS = [
  { path: 'js/ui-kit/src/tokens.css', render: renderCss, prettier: true },
  { path: 'js/ui-kit/src/generated/tokens.ts', render: renderTypeScript, prettier: true },
  { path: 'js/ui-kit/src/generated/theme.v1.json', render: renderThemeSchema, prettier: true },
  { path: 'docs/ui/tokens.md', render: renderDocs, prettier: false },
];

/**
 * @param {string} path
 * @returns {string | null}
 */
function readOrNull(path) {
  try {
    return readFileSync(path, 'utf8');
  } catch {
    return null;
  }
}

/**
 * Generates every output below the root and returns the paths it wrote, relative to the root.
 *
 * @param {string} root
 * @returns {Promise<string[]>}
 */
export async function generateTokens(root) {
  const { catalogue, problems } = readCatalogue(readFileSync(join(root, SOURCE), 'utf8'));

  if (problems.length > 0) {
    throw new CatalogueInvalid(problems);
  }

  /** @type {string[]} */
  const written = [];

  for (const output of OUTPUTS) {
    const rendered = output.render(catalogue);
    const contents = output.prettier
      ? await format(rendered, { ...prettierConfig, filepath: output.path })
      : rendered;
    const path = join(root, output.path);

    if (readOrNull(path) !== contents) {
      mkdirSync(dirname(path), { recursive: true });
      writeFileSync(path, contents);
      written.push(output.path);
    }
  }

  return written;
}

/** The catalogue has problems, each a line of the message. */
export class CatalogueInvalid extends Error {
  /** @param {string[]} problems */
  constructor(problems) {
    super(problems.map((problem) => `${SOURCE}: ${problem}`).join('\n'));
    this.name = 'CatalogueInvalid';
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const option = process.argv.slice(2).find((argument) => argument.startsWith('--root='));
  const root = resolve(
    option === undefined
      ? join(dirname(fileURLToPath(import.meta.url)), '../../..')
      : option.slice(7),
  );

  try {
    for (const path of await generateTokens(root)) {
      process.stdout.write(`wrote ${path}\n`);
    }
  } catch (error) {
    if (!(error instanceof CatalogueInvalid)) {
      throw error;
    }

    process.stderr.write(`${error.message}\n`);
    process.exit(65);
  }
}
